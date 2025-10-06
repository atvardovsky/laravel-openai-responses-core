<?php declare(strict_types=1);

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Illuminate\Support\Facades\Http;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;

/**
 * Vector Store Service
 * 
 * Manages OpenAI vector stores for file search functionality in Responses API.
 * Handles creating vector stores, uploading files, and managing vector store lifecycle.
 * 
 * @package Atvardovsky\LaravelOpenAIResponses\Services
 */
class VectorStoreService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('ai_responses.api_key') 
            ?? config('services.openai.api_key') 
            ?? env('OPENAI_API_KEY');
            
        $this->baseUrl = config('ai_responses.base_url', 'https://api.openai.com/v1');
        
        if (empty($this->apiKey)) {
            throw new AIResponseException('OpenAI API key not configured');
        }
    }

    /**
     * Create a new vector store
     * 
     * @param string $name Vector store name
     * @param array $fileIds Optional array of file IDs to add
     * @param array $metadata Optional metadata
     * @return array Vector store details
     */
    public function create(string $name, array $fileIds = [], array $metadata = []): array
    {
        $payload = [
            'name' => $name,
        ];
        
        if (!empty($fileIds)) {
            $payload['file_ids'] = $fileIds;
        }
        
        if (!empty($metadata)) {
            $payload['metadata'] = $metadata;
        }
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Beta' => 'assistants=v2'
        ])->post($this->baseUrl . '/vector_stores', $payload);
        
        if (!$response->successful()) {
            throw new AIResponseException(
                'Failed to create vector store: ' . $response->body(),
                $response->status()
            );
        }
        
        return $response->json();
    }

    /**
     * Upload a file to OpenAI for vector store usage
     * 
     * @param string $content File content
     * @param string $filename File name
     * @return array File details including file ID
     */
    public function uploadFile(string $content, string $filename): array
    {
        $tempFile = tmpfile();
        $tempPath = stream_get_meta_data($tempFile)['uri'];
        fwrite($tempFile, $content);
        fseek($tempFile, 0);
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->attach('file', file_get_contents($tempPath), $filename)
              ->post($this->baseUrl . '/files', [
                  'purpose' => 'assistants'
              ]);
            
            if (!$response->successful()) {
                throw new AIResponseException(
                    'Failed to upload file: ' . $response->body(),
                    $response->status()
                );
            }
            
            return $response->json();
        } finally {
            fclose($tempFile);
        }
    }

    /**
     * Add file to existing vector store
     * 
     * @param string $vectorStoreId Vector store ID
     * @param string $fileId File ID
     * @return array Vector store file details
     */
    public function addFile(string $vectorStoreId, string $fileId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Beta' => 'assistants=v2'
        ])->post($this->baseUrl . "/vector_stores/{$vectorStoreId}/files", [
            'file_id' => $fileId
        ]);
        
        if (!$response->successful()) {
            throw new AIResponseException(
                'Failed to add file to vector store: ' . $response->body(),
                $response->status()
            );
        }
        
        return $response->json();
    }

    /**
     * Get vector store details
     * 
     * @param string $vectorStoreId Vector store ID
     * @return array Vector store details
     */
    public function get(string $vectorStoreId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Beta' => 'assistants=v2'
        ])->get($this->baseUrl . "/vector_stores/{$vectorStoreId}");
        
        if (!$response->successful()) {
            throw new AIResponseException(
                'Failed to get vector store: ' . $response->body(),
                $response->status()
            );
        }
        
        return $response->json();
    }

    /**
     * Delete a vector store
     * 
     * @param string $vectorStoreId Vector store ID
     * @return array Deletion confirmation
     */
    public function delete(string $vectorStoreId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Beta' => 'assistants=v2'
        ])->delete($this->baseUrl . "/vector_stores/{$vectorStoreId}");
        
        if (!$response->successful()) {
            throw new AIResponseException(
                'Failed to delete vector store: ' . $response->body(),
                $response->status()
            );
        }
        
        return $response->json();
    }

    /**
     * Create vector store from schema and upload
     * 
     * Helper method that handles the full workflow:
     * 1. Upload schema file
     * 2. Create vector store
     * 3. Add file to vector store
     * 
     * @param string $schemaContent JSON schema content
     * @param string $name Vector store name
     * @return array Contains vector_store_id and file_id
     */
    public function createFromSchema(string $schemaContent, string $name): array
    {
        // Upload the schema file
        $filename = 'db-schema-' . now()->format('Y-m-d-His') . '.json';
        $fileResponse = $this->uploadFile($schemaContent, $filename);
        $fileId = $fileResponse['id'];
        
        // Create vector store with the file
        $vectorStore = $this->create($name, [$fileId], [
            'purpose' => 'database_schema',
            'created_at' => now()->toIso8601String()
        ]);
        
        return [
            'vector_store_id' => $vectorStore['id'],
            'file_id' => $fileId,
            'name' => $name,
            'status' => $vectorStore['status'] ?? 'created'
        ];
    }
}
