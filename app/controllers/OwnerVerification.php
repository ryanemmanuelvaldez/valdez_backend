<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OwnerVerification extends Controller
{
    public function documents()
    {
        $this->require_method('GET');
        $owner = $this->current_owner();
        $documents = $this->call->database()->table('owner_verification_documents')
            ->select('id, document_type, file_name, created_at')
            ->where('owner_id', (int) $owner['id'])
            ->order_by('created_at', 'ASC')
            ->get_all();
        $this->respond(200, ['success' => true, 'documents' => $documents]);
    }

    public function upload_documents()
    {
        $this->require_method('POST');
        $owner = $this->current_owner();
        $files = $_FILES['documents'] ?? [];
        $required_types = ['government_id', 'ownership_proof'];
        $document_types = [
            'government_id' => 'Government-issued photo ID',
            'ownership_proof' => 'Proof of property ownership or management',
            'authorization_letter' => 'Authorization letter (if acting for the owner)',
        ];
        foreach ($required_types as $type) {
            if (!isset($files['name'][$type]) || (int) ($files['error'][$type] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->respond(422, ['success' => false, 'message' => 'Upload the required ' . $document_types[$type] . ' PDF before submitting.']);
            }
        }
        $db = $this->call->database();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $validated_files = [];
        foreach ($document_types as $type => $label) {
            $upload_error = (int) ($files['error'][$type] ?? UPLOAD_ERR_NO_FILE);
            if (!isset($files['name'][$type]) || $upload_error === UPLOAD_ERR_NO_FILE) continue;
            if ($upload_error !== UPLOAD_ERR_OK) {
                $this->respond(422, ['success' => false, 'message' => 'The ' . $label . ' upload failed. Choose a PDF under 2 MB and try again.']);
            }
            $tmp_name = (string) ($files['tmp_name'][$type] ?? '');
            $size = (int) ($files['size'][$type] ?? 0);
            if ($size < 1 || $size > 2 * 1024 * 1024 || !is_uploaded_file($tmp_name)) {
                $this->respond(422, ['success' => false, 'message' => 'Each PDF must be a valid file no larger than 2 MB.']);
            }
            $mime = $finfo->file($tmp_name);
            if ($mime !== 'application/pdf') $this->respond(422, ['success' => false, 'message' => 'Only PDF files are accepted for owner verification.']);
            $data = file_get_contents($tmp_name);
            if ($data === false || strpos($data, '%PDF-') !== 0) $this->respond(422, ['success' => false, 'message' => 'The ' . $label . ' must be a valid PDF document.']);

            $name = basename(str_replace('\\', '/', (string) $files['name'][$type]));
            $validated_files[] = [
                'owner_id' => (int) $owner['id'],
                'document_type' => $type,
                'file_name' => substr($name, 0, 255),
                'mime_type' => $mime,
                'file_data' => $data,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        $db->transaction();
        foreach ($validated_files as $document) {
            $db->table('owner_verification_documents')
                ->where('owner_id', (int) $owner['id'])
                ->where('document_type', $document['document_type'])
                ->delete();
            $db->table('owner_verification_documents')->insert($document);
        }

        if ($owner['owner_status'] !== 'verified') {
            $db->table('users')->where('id', (int) $owner['id'])->update([
                'owner_status' => 'pending',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->commit();

        $this->respond(201, [
            'success' => true,
            'message' => count($validated_files) . ' PDF document' . (count($validated_files) === 1 ? '' : 's') . ' submitted for owner verification.',
        ]);
    }

    private function current_owner()
    {
        $this->call->library('session');
        $user_id = (int) load_class('session', 'libraries')->userdata('user_id');
        $owner = $user_id > 0
            ? $this->call->database()->table('users')->where('id', $user_id)->row_array()
            : null;
        if (!$owner || !(bool) $owner['is_active']) {
            $this->respond(401, ['success' => false, 'message' => 'Sign in as a property owner to continue.']);
        }
        if ($owner['role'] === 'admin' || $owner['account_type'] !== 'owner') {
            $this->respond(403, ['success' => false, 'message' => 'Only property owners can submit verification documents.']);
        }
        return $owner;
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
