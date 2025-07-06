<?php

namespace App\Services;

interface ImportServiceInterface
{
    public function importFromExcel($file, $userId);
    public function updateImport($importId, $data);
    public function deleteImport($importId);
    public function getAllImports($perPage = 15);
    public function getImport($importId);
} 