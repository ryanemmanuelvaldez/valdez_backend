<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Sales_model extends Model
{
    protected $table = 'rental_payments';

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
    }

    public function platformSales(): array
    {
        $db = $this->call->database();
        $records = $this->paid_records_sql();
        $totals = $db->raw(
            'SELECT COUNT(*) AS transactions, COALESCE(SUM(paid_records.amount_centavos), 0) AS total_centavos '
            . 'FROM (' . $records['sql'] . ') paid_records',
            $records['params']
        )->fetch(PDO::FETCH_ASSOC);
        $monthly = $this->monthly_sales(null, 12);

        return [
            'transactions' => (int) ($totals['transactions'] ?? 0),
            'total_centavos' => (int) ($totals['total_centavos'] ?? 0),
            'monthly' => $monthly,
            'owner_payments' => $this->owner_breakdown(),
            'forecast' => $this->forecast($monthly),
        ];
    }

    public function ownerSales(int $owner_id): array
    {
        $records = $this->paid_records_sql($owner_id);
        $totals = $this->call->database()->raw(
            'SELECT COUNT(*) AS transactions, COALESCE(SUM(paid_records.amount_centavos), 0) AS total_centavos '
            . 'FROM (' . $records['sql'] . ') paid_records',
            $records['params']
        )->fetch(PDO::FETCH_ASSOC);
        $monthly = $this->monthly_sales($owner_id, 12);

        return [
            'transactions' => (int) ($totals['transactions'] ?? 0),
            'total_centavos' => (int) ($totals['total_centavos'] ?? 0),
            'monthly_income' => $monthly,
            'forecast' => $this->forecast($monthly),
        ];
    }

    private function monthly_sales(?int $owner_id, int $month_count): array
    {
        $end = new DateTimeImmutable('first day of this month');
        $start = $end->modify('-' . ($month_count - 1) . ' months');
        $records = $this->paid_records_sql($owner_id);
        $rows = $this->call->database()->raw(
            'SELECT paid_records.month, COUNT(*) AS transactions, SUM(paid_records.amount_centavos) AS amount_centavos '
            . 'FROM (' . $records['sql'] . ') paid_records '
            . 'WHERE paid_records.month >= ? AND paid_records.month <= ? '
            . 'GROUP BY paid_records.month ORDER BY paid_records.month ASC',
            array_merge($records['params'], [$start->format('Y-m'), $end->format('Y-m')])
        )->fetchAll(PDO::FETCH_ASSOC);
        $by_month = [];
        foreach ($rows as $row) {
            $by_month[$row['month']] = [
                'transactions' => (int) $row['transactions'],
                'amount_centavos' => (int) $row['amount_centavos'],
            ];
        }

        $monthly = [];
        for ($offset = $month_count - 1; $offset >= 0; $offset--) {
            $date = $end->modify('-' . $offset . ' months');
            $month = $date->format('Y-m');
            $monthly[] = [
                'month' => $month,
                'transactions' => $by_month[$month]['transactions'] ?? 0,
                'amount_centavos' => $by_month[$month]['amount_centavos'] ?? 0,
            ];
        }

        return $monthly;
    }

    private function owner_breakdown(): array
    {
        $records = $this->paid_records_sql();
        $rows = $this->call->database()->raw(
            'SELECT owner.id AS owner_id, COALESCE(NULLIF(owner.full_name, \'\'), owner.username) AS owner_name, owner.email, '
            . 'COUNT(paid_records.amount_centavos) AS transactions, COALESCE(SUM(paid_records.amount_centavos), 0) AS amount_centavos '
            . 'FROM users owner LEFT JOIN (' . $records['sql'] . ') paid_records ON paid_records.owner_id = owner.id '
            . 'WHERE owner.account_type = ? '
            . 'GROUP BY owner.id, owner.full_name, owner.username, owner.email '
            . 'ORDER BY amount_centavos DESC, owner_name ASC',
            array_merge($records['params'], ['owner'])
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['owner_id'] = (int) $row['owner_id'];
            $row['transactions'] = (int) $row['transactions'];
            $row['amount_centavos'] = (int) $row['amount_centavos'];
        }
        unset($row);

        return $rows;
    }

    private function forecast(array $monthly): array
    {
        $completed_months = array_slice($monthly, 0, -1);
        $history = array_slice($completed_months, -6);
        $history_count = count($history);
        $positive_months = 0;
        $sum_x = 0.0;
        $sum_y = 0.0;
        $sum_xy = 0.0;
        $sum_x_squared = 0.0;

        foreach ($history as $index => $item) {
            $amount = (float) $item['amount_centavos'];
            if ($amount > 0) {
                $positive_months++;
            }
            $sum_x += $index;
            $sum_y += $amount;
            $sum_xy += $index * $amount;
            $sum_x_squared += $index * $index;
        }

        $denominator = ($history_count * $sum_x_squared) - ($sum_x * $sum_x);
        $slope = $denominator !== 0.0
            ? (($history_count * $sum_xy) - ($sum_x * $sum_y)) / $denominator
            : 0.0;
        $average = $history_count > 0 ? $sum_y / $history_count : 0.0;
        $end = new DateTimeImmutable('first day of this month');
        $projection = [];

        for ($step = 1; $step <= 3; $step++) {
            $month = $end->modify('+' . $step . ' months');
            $amount = $history_count > 0
                ? (int) max(0, round($average + ($slope * (($history_count - 1) + $step))))
                : 0;
            $projection[] = [
                'month' => $month->format('Y-m'),
                'amount_centavos' => $amount,
            ];
        }

        return [
            'method' => 'linear_trend',
            'basis_months' => $history_count,
            'months_with_sales' => $positive_months,
            'signal' => $positive_months >= 3 ? 'more_history' : 'limited_history',
            'projected_months' => $projection,
        ];
    }

    private function paid_records_sql(?int $owner_id = null): array
    {
        $owner_filter = $owner_id === null ? '' : ' AND rr.owner_id = ?';
        $params = $owner_id === null ? [] : [$owner_id, $owner_id];
        $sql = 'SELECT rr.owner_id, DATE_FORMAT(COALESCE(rp.paid_at, rp.created_at), \'%Y-%m\') AS month, rp.amount_centavos '
            . 'FROM rental_payments rp INNER JOIN rental_requests rr ON rr.id = rp.rental_request_id '
            . 'WHERE rp.status = \'paid\'' . $owner_filter . ' '
            . 'UNION ALL '
            . 'SELECT rr.owner_id, DATE_FORMAT(COALESCE(ri.paid_at, ri.created_at), \'%Y-%m\') AS month, ri.amount_centavos '
            . 'FROM rental_installments ri INNER JOIN rental_requests rr ON rr.id = ri.rental_request_id '
            . 'WHERE ri.status = \'paid\' AND ri.installment_number > 1' . $owner_filter;

        return ['sql' => $sql, 'params' => $params];
    }
}
