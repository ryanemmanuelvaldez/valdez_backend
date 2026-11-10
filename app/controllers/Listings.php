<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Listings extends Controller
{
    private const PROPERTY_TYPES = ['bedspacer', 'room', 'apartment'];

    public function index()
    {
        $this->require_method('GET');
        $type = trim((string) ($_GET['type'] ?? ''));
        if ($type !== '' && !in_array($type, self::PROPERTY_TYPES, true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose bedspacer, room, or apartment.']);
        }

        $this->call->model('Rental_model');
        $listings = $this->Rental_model->publishedListings($type);
        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalCapacityService.php';
        $capacity_service = new RentalCapacityService($this->call->database());
        $rental_ids = array_column($listings, 'id');
        $occupied_by_rental = $capacity_service->occupiedSlotsByRentalIds($rental_ids);
        foreach ($listings as &$listing) {
            $capacity = $capacity_service->capacity($listing);
            $occupied = $occupied_by_rental[(int) $listing['id']] ?? 0;
            $listing['total_capacity'] = $capacity;
            $listing['occupied_slots'] = $occupied;
            $listing['available_slots'] = max(0, $capacity - $occupied);
            $listing['reservation_pending'] = $occupied > 0;
        }
        unset($listing);

        $this->respond(200, ['success' => true, 'listings' => $listings]);
    }

    public function photo($id)
    {
        $this->require_method('GET');
        $listing = $this->call->database()->table('rentals')->where('id', (int) $id)->row_array();
        if (!$listing || !is_string($listing['photo_url'] ?? null) || strpos($listing['photo_url'], 'local:') !== 0) {
            $this->respond(404, ['success' => false, 'message' => 'This property photo was not found.']);
        }

        if ($listing['status'] !== 'published') {
            $user = $this->current_user();
            if (!$user || ($user['role'] !== 'admin' && (int) $user['id'] !== (int) $listing['owner_id'])) {
                $this->respond(404, ['success' => false, 'message' => 'This property photo was not found.']);
            }
        }

        $photo_service = $this->call->library('property_photo');
        $path = $photo_service->file_path($listing['photo_url']);
        if ($path === null) {
            $this->respond(404, ['success' => false, 'message' => 'This property photo was not found.']);
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function mine()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to view your listings.']);
        }
        if ($user['account_type'] !== 'owner' || $user['role'] === 'admin') {
            $this->respond(403, ['success' => false, 'message' => 'Only property owners can view owner listings.']);
        }

        $this->call->model('Rental_model');
        $listings = $this->Rental_model->findOwnedListings((int) $user['id']);
        $this->respond(200, ['success' => true, 'listings' => $listings]);
    }

    public function locations_mine()
    {
        $this->require_method('GET');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to view your property locations.']);
        }
        if ($user['account_type'] !== 'owner' || $user['role'] === 'admin') {
            $this->respond(403, ['success' => false, 'message' => 'Only property owners can view owner property locations.']);
        }

        $locations = $this->call->database()
            ->table('rentals')
            ->select('id, title, description, property_type, price, location, address, unit_number, rooms_count, bedrooms, bed_count, bathrooms, max_occupants, floor_area, furnishing, utilities_included, available_from, security_deposit, amenities, contact_phone, photo_url, updated_at, status, latitude, longitude')
            ->where('owner_id', (int) $user['id'])
            ->order_by('created_at', 'DESC')
            ->get_all();
        $owner = $this->call->database()
            ->table('users')
            ->select('email')
            ->where('id', (int) $user['id'])
            ->row_array();
        foreach ($locations as &$location) {
            $location['owner_email'] = $owner['email'] ?? '';
        }
        unset($location);

        $this->respond(200, ['success' => true, 'locations' => $locations]);
    }

    public function update_mine($id)
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) $this->respond(401, ['success' => false, 'message' => 'Sign in to edit your rental listings.']);
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner' || ($user['owner_status'] ?? '') !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'A verified property owner account is required to edit listings.']);
        }

        $this->call->model('Rental_model');
        $rentals = $this->Rental_model;
        $listing = $rentals->findOwnedListing((int) $id, (int) $user['id']);
        if (!$listing) $this->respond(404, ['success' => false, 'message' => 'This rental listing was not found for your account.']);

        $submitted = $this->listing_input();
        unset($submitted['photo_url']);
        $input = array_merge($listing, $submitted);
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = (string) ($input['property_type'] ?? '');
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $location = trim((string) ($input['location'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $unit_number = trim((string) ($input['unit_number'] ?? ''));
        [$latitude, $longitude] = $this->validated_coordinates($input);
        $rooms = filter_var($input['rooms_count'] ?? $input['bedrooms'] ?? null, FILTER_VALIDATE_INT);
        $beds = filter_var($input['bed_count'] ?? 0, FILTER_VALIDATE_INT);
        $bathrooms = filter_var($input['bathrooms'] ?? null, FILTER_VALIDATE_INT);
        $max_occupants = filter_var($input['max_occupants'] ?? null, FILTER_VALIDATE_INT);
        $floor_area_value = ($input['floor_area'] ?? '') === '' ? null : filter_var($input['floor_area'], FILTER_VALIDATE_FLOAT);
        $furnishing = (string) ($input['furnishing'] ?? '');
        $utilities = filter_var($input['utilities_included'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $available_from = trim((string) ($input['available_from'] ?? ''));
        $deposit = filter_var($input['security_deposit'] ?? null, FILTER_VALIDATE_FLOAT);
        $amenities = trim((string) ($input['amenities'] ?? ''));
        $phone = trim((string) ($input['contact_phone'] ?? ''));

        if (strlen($title) < 4 || strlen($title) > 120 || strlen($description) < 20 || strlen($description) > 5000) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a title between 4–120 characters and a description between 20–5000 characters.']);
        }
        if (!in_array($type, self::PROPERTY_TYPES, true) || !is_numeric($price) || $price < 1 || $price > 99999999.99
            || strlen($location) < 3 || strlen($location) > 180 || strlen($address) < 5 || strlen($address) > 180 || strlen($unit_number) > 40) {
            $this->respond(422, ['success' => false, 'message' => 'Check the property type, rent, location, address, and unit details.']);
        }
        if ($rooms === false || $rooms < 0 || $rooms > 50 || $beds === false || $beds < 0 || $beds > 500
            || ($type === 'bedspacer' && $beds < 1) || $bathrooms === false || $bathrooms < 0 || $bathrooms > 50
            || $max_occupants === false || $max_occupants < 1 || $max_occupants > 500) {
            $this->respond(422, ['success' => false, 'message' => 'Enter valid room, bed, bathroom, and occupant counts.']);
        }
        if ($floor_area_value !== null && (!is_numeric($floor_area_value) || $floor_area_value < 1 || $floor_area_value > 99999)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid floor area.']);
        }
        if (!in_array($furnishing, ['unfurnished', 'semi_furnished', 'fully_furnished'], true)
            || $utilities === null || strlen($amenities) > 1000) {
            $this->respond(422, ['success' => false, 'message' => 'Check furnishing, utilities, and amenities.']);
        }
        $date = DateTime::createFromFormat('!Y-m-d', $available_from);
        if (!$date || $date->format('Y-m-d') !== $available_from) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a valid property availability date.']);
        }
        if (!is_numeric($deposit) || $deposit < 0 || $deposit > 99999999.99) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid security deposit.']);
        }
        $phone_digits = preg_replace('/\D/', '', $phone);
        if (strlen($phone) > 30 || strlen($phone_digits) < 7 || strlen($phone_digits) > 20 || !preg_match('/^[0-9+().\-\s]+$/', $phone)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid contact phone number.']);
        }
        $photo_service = $this->call->library('property_photo');
        $new_photo = null;
        if (isset($_FILES['property_photo']) && (int) ($_FILES['property_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $new_photo = $photo_service->store($_FILES['property_photo']);
            } catch (InvalidArgumentException $exception) {
                $this->respond(422, ['success' => false, 'message' => $exception->getMessage()]);
            }
        }

        try {
            $updated = $rentals->updateOwnedListing((int) $id, (int) $user['id'], [
                'title' => $title,
                'description' => $description,
                'property_type' => $type,
                'price' => number_format((float) $price, 2, '.', ''),
                'location' => $location,
                'address' => $address,
                'unit_number' => $unit_number,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'bedrooms' => $rooms,
                'rooms_count' => $rooms,
                'bed_count' => $beds,
                'bathrooms' => $bathrooms,
                'max_occupants' => $max_occupants,
                'floor_area' => $floor_area_value === null ? null : number_format((float) $floor_area_value, 2, '.', ''),
                'furnishing' => $furnishing,
                'utilities_included' => $utilities ? 1 : 0,
                'available_from' => $available_from,
                'security_deposit' => number_format((float) $deposit, 2, '.', ''),
                'amenities' => $amenities,
                'contact_phone' => $phone,
                'photo_url' => $new_photo ?? $listing['photo_url'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            if ($new_photo !== null) $photo_service->delete($new_photo);
            throw $exception;
        }
        if ($updated === 0 && $new_photo !== null) {
            $photo_service->delete($new_photo);
            $this->respond(500, ['success' => false, 'message' => 'Your property photo could not be saved.']);
        }
        if ($new_photo !== null && strpos((string) $listing['photo_url'], 'local:') === 0) {
            $photo_service->delete($listing['photo_url']);
        }
        $this->respond(200, ['success' => true, 'message' => 'Your property listing has been updated.']);
    }

    public function create()
    {
        $this->require_method('POST');
        $user = $this->current_user();
        if (!$user) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in to submit a rental.']);
        }
        if ($user['role'] === 'admin' || $user['account_type'] !== 'owner') {
            $this->respond(403, ['success' => false, 'message' => 'Only property owners can submit rentals.']);
        }
        if (($user['owner_status'] ?? '') !== 'verified') {
            $this->respond(403, ['success' => false, 'message' => 'Your property owner account must be verified before submitting a rental.']);
        }

        $input = $this->listing_input();
        unset($input['photo_url']);
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = (string) ($input['property_type'] ?? '');
        $location = trim((string) ($input['location'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $address = trim((string) ($input['address'] ?? ''));
        $unit_number = trim((string) ($input['unit_number'] ?? ''));
        [$latitude, $longitude] = $this->validated_coordinates($input);
        $rooms_count = filter_var($input['rooms_count'] ?? $input['bedrooms'] ?? null, FILTER_VALIDATE_INT);
        $bed_count = filter_var($input['bed_count'] ?? 0, FILTER_VALIDATE_INT);
        $bathrooms = filter_var($input['bathrooms'] ?? null, FILTER_VALIDATE_INT);
        $max_occupants = filter_var($input['max_occupants'] ?? null, FILTER_VALIDATE_INT);
        $floor_area = ($input['floor_area'] ?? '') === '' ? null : filter_var($input['floor_area'], FILTER_VALIDATE_FLOAT);
        $furnishing = (string) ($input['furnishing'] ?? '');
        $utilities_included = $input['utilities_included'] ?? null;
        $available_from = trim((string) ($input['available_from'] ?? ''));
        $security_deposit = filter_var($input['security_deposit'] ?? null, FILTER_VALIDATE_FLOAT);
        $amenities = trim((string) ($input['amenities'] ?? ''));
        $contact_phone = trim((string) ($input['contact_phone'] ?? ''));

        if (strlen($title) < 4 || strlen($title) > 120) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a title between 4 and 120 characters.']);
        }
        if (strlen($description) < 20 || strlen($description) > 5000) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a description between 20 and 5000 characters.']);
        }
        if (!in_array($type, self::PROPERTY_TYPES, true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose bedspacer, room, or apartment.']);
        }
        if (!is_numeric($price) || (float) $price < 1 || (float) $price > 99999999.99) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid monthly rent.']);
        }
        if (strlen($location) < 3 || strlen($location) > 180) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a location between 3 and 180 characters.']);
        }
        if (strlen($address) < 5 || strlen($address) > 180 || strlen($unit_number) > 40) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid street address and unit number.']);
        }
        if ($rooms_count === false || $rooms_count < 0 || $rooms_count > 50
            || $bed_count === false || $bed_count < 0 || $bed_count > 500
            || ($type === 'bedspacer' && $bed_count < 1)
            || $bathrooms === false || $bathrooms < 0 || $bathrooms > 50
            || $max_occupants === false || $max_occupants < 1 || $max_occupants > 500) {
            $this->respond(422, ['success' => false, 'message' => 'Enter valid room, bed, bathroom, and occupant counts. Bedspacers require at least one bed.']);
        }
        if ($floor_area !== null && (!is_numeric($floor_area) || $floor_area < 1 || $floor_area > 99999)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid floor area in square meters.']);
        }
        if (!in_array($furnishing, ['unfurnished', 'semi_furnished', 'fully_furnished'], true)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a furnishing option.']);
        }
        if (!is_bool($utilities_included)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose whether utilities are included.']);
        }
        $date = DateTime::createFromFormat('!Y-m-d', $available_from);
        if (!$date || $date->format('Y-m-d') !== $available_from || $available_from < date('Y-m-d')) {
            $this->respond(422, ['success' => false, 'message' => 'Choose an availability date today or later.']);
        }
        if (!is_numeric($security_deposit) || $security_deposit < 0 || $security_deposit > 99999999.99) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid security deposit.']);
        }
        if (strlen($amenities) > 1000) {
            $this->respond(422, ['success' => false, 'message' => 'Keep amenities under 1000 characters.']);
        }
        $phone_digits = preg_replace('/\D/', '', $contact_phone);
        if (strlen($contact_phone) > 30 || strlen($phone_digits) < 7 || strlen($phone_digits) > 20
            || !preg_match('/^[0-9+().\-\s]+$/', $contact_phone)) {
            $this->respond(422, ['success' => false, 'message' => 'Enter a valid contact phone number.']);
        }
        $photo_service = $this->call->library('property_photo');
        try {
            $photo_url = $photo_service->store($_FILES['property_photo'] ?? []);
        } catch (InvalidArgumentException $exception) {
            $this->respond(422, ['success' => false, 'message' => $exception->getMessage()]);
        }
        $this->call->model('Rental_model');
        try {
            $id = $this->Rental_model->createListing([
            'owner_id' => (int) $user['id'],
            'title' => $title,
            'description' => $description,
            'property_type' => $type,
            'price' => number_format((float) $price, 2, '.', ''),
            'location' => $location,
            'address' => $address,
            'unit_number' => $unit_number,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'bedrooms' => $rooms_count,
            'rooms_count' => $rooms_count,
            'bed_count' => $bed_count,
            'bathrooms' => $bathrooms,
            'max_occupants' => $max_occupants,
            'floor_area' => $floor_area === null ? null : number_format((float) $floor_area, 2, '.', ''),
            'furnishing' => $furnishing,
            'utilities_included' => $utilities_included ? 1 : 0,
            'available_from' => $available_from,
            'security_deposit' => number_format((float) $security_deposit, 2, '.', ''),
            'amenities' => $amenities,
            'contact_phone' => $contact_phone,
            'photo_url' => $photo_url,
            'status' => 'pending',
            'is_featured' => 0,
            ]);
        } catch (Throwable $exception) {
            $photo_service->delete($photo_url);
            throw $exception;
        }
        if (!$id) {
            $photo_service->delete($photo_url);
            $this->respond(500, ['success' => false, 'message' => 'The rental listing could not be saved.']);
        }
        $this->respond(201, ['success' => true, 'message' => 'Rental submitted for admin review.', 'id' => (int) $id]);
    }

    private function current_user()
    {
        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        if ($user_id < 1) {
            return null;
        }

        $this->call->model('User_model');
        $user = $this->User_model->findById($user_id);

        return $user && (bool) $user['is_active'] ? $user : null;
    }

    private function validated_coordinates(array $input): array
    {
        $latitude = trim((string) ($input['latitude'] ?? ''));
        $longitude = trim((string) ($input['longitude'] ?? ''));
        if ($latitude === '' && $longitude === '') {
            return [null, null];
        }
        if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
            $this->respond(422, ['success' => false, 'message' => 'Choose a property location on the map or enter both coordinates.']);
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (!is_finite($latitude) || !is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $this->respond(422, ['success' => false, 'message' => 'Enter valid map coordinates.']);
        }

        return [
            number_format($latitude, 7, '.', ''),
            number_format($longitude, 7, '.', ''),
        ];
    }

    private function listing_input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
            $input = $_POST;
            $utilities = filter_var(
                $input['utilities_included'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
            if ($utilities === null) {
                $this->respond(422, ['success' => false, 'message' => 'Choose whether utilities are included.']);
            }
            $input['utilities_included'] = $utilities;
            return $input;
        }
        return $this->json_input();
    }

    private function json_input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
            $this->respond(415, ['success' => false, 'message' => 'Send the request as JSON.']);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            $this->respond(400, ['success' => false, 'message' => 'The request body must be valid JSON.']);
        }
        return $input;
    }

    private function require_method($method)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            $this->respond(405, ['success' => false, 'message' => 'This request method is not allowed.']);
        }
    }

    private function respond($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
