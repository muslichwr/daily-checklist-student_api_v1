<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Google\Service\FirebaseCloudMessaging;

class FCMService
{
    protected $projectId;
    protected $serviceAccountPath;
    protected $accessToken;

    public function __construct()
    {
        // Get project ID from service account file
        $this->projectId = env('FCM_PROJECT_ID', 'daily-checklist-student');
        $this->serviceAccountPath = storage_path('credentials/daily-checklist-student-69cf10d6f307.json');
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Get OAuth2 access token from Google
     *
     * @return string|null
     */
    protected function getAccessToken()
    {
        try {
            if (!file_exists($this->serviceAccountPath)) {
                Log::error('FCM service account file not found at: ' . $this->serviceAccountPath);
                return null;
            }

            // Create client and specify scopes
            $client = new Client();
            $client->setAuthConfig($this->serviceAccountPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            
            // Get credentials and token
            $client->fetchAccessTokenWithAssertion();
            $accessToken = $client->getAccessToken();
            
            return $accessToken['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('Error getting FCM access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send FCM notification to a specific device
     *
     * @param string|null $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return bool Success status
     */
    public function sendNotificationToDevice($fcmToken, $title, $body, $data = [])
    {
        if (!$fcmToken || !$this->accessToken) {
            return false;
        }

        $message = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
                'android' => [
                    'notification' => [
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
                        ],
                    ],
                ],
            ]
        ];

        return $this->sendMessageToFcm($message);
    }

    /**
     * Send FCM notification to multiple devices
     *
     * @param array $fcmTokens
     * @param string $title
     * @param string $body
     * @param array $data
     * @return bool Success status
     */
    public function sendNotificationToDevices($fcmTokens, $title, $body, $data = [])
    {
        if (empty($fcmTokens) || !$this->accessToken) {
            return false;
        }

        $successCount = 0;
        foreach ($fcmTokens as $token) {
            $success = $this->sendNotificationToDevice($token, $title, $body, $data);
            if ($success) {
                $successCount++;
            }
        }
        
        return $successCount > 0;
    }

    /**
     * Send the FCM message to FCM
     *
     * @param array $message
     * @return bool Success status
     */
    protected function sendMessageToFcm($message)
    {
        try {
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->post($url, $message);

            if ($response->successful()) {
                Log::info('FCM notification sent successfully', [
                    'response' => $response->json(),
                ]);
                return true;
            } else {
                Log::error('FCM notification failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception when sending FCM notification: ' . $e->getMessage());
            return false;
        }
    }
} 