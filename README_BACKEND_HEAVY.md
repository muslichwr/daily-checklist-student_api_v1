# Backend-Heavy Architecture for Daily Checklist Student API

This document explains the implementation of a Backend-Heavy architecture pattern in the Daily Checklist Student API, using notifications as an example.

## What is Backend-Heavy Architecture?

Backend-Heavy architecture is a design pattern that prioritizes moving business logic and processing to the server-side rather than the client. This approach:

- Centralizes logic in one place for easier maintenance
- Reduces code duplication across multiple client platforms
- Improves security by keeping sensitive logic server-side
- Simplifies client-side code
- Ensures consistent behavior across all clients

## Implementation Example: Notifications System

The notification system demonstrates the Backend-Heavy architecture:

### 1. Database Structure

- `notifications` table: Stores all notification data
- `firebase_tokens` table: Manages FCM tokens for push notifications

### 2. Controllers Structure

- `NotificationController.php`: Handles CRUD operations and basic notification management
- `NotificationSystemController.php`: Handles specialized notification logic for different scenarios

### 3. Key Implementation Features

#### Automatic Notification Generation

Instead of requiring the client to create notifications explicitly, the backend automatically generates them when specific events occur:

```php
// Example: When a teacher creates a new plan
public function notifyNewActivityPlan(Request $request)
{
    // Validation and authorization checks
    
    $success = $this->notificationController->sendNewPlanNotification(
        $request->plan_id,
        Auth::id(),
        $request->child_id,
        $request->plan_title
    );
    
    // Response handling
}
```

#### Push Notification Handling

Push notifications are handled server-side:

```php
public function sendPushNotification(Notification $notification)
{
    // Check for registered tokens
    // Send FCM notifications
    // Handle failures and token invalidation
}
```

#### Token Management

The server manages FCM tokens:

```php
public function registerFirebaseToken(Request $request)
{
    // Register or update Firebase token
    FirebaseToken::updateOrCreate(
        ['token' => $token],
        [
            'user_id' => $user->id,
            'device_info' => $request->device_info ?? null,
            'is_active' => true,
            'last_used' => now()
        ]
    );
}
```

## How to Implement Backend-Heavy for Other Features

To apply this pattern to other features in your application:

1. **Identify Client Logic to Move**: Look for validation, business rules, or complex operations happening in the client.

2. **Create Specialized Controllers**: Create controllers that handle specific business processes rather than just CRUD operations.

3. **Implement Domain Events**: Use Laravel events to trigger notifications or other side effects automatically.

4. **Use Transactions**: Ensure data consistency by wrapping related operations in database transactions.

5. **Return Complete Responses**: Return fully formed responses so clients don't need to make multiple requests.

### Example Implementation Steps

#### For a Feature Like "Activity Planning":

1. **Create Specialized Methods**:
```php
public function completeActivity(Request $request, $id)
{
    // Authorization
    // Validation
    // Business logic
    // Send notifications
    // Return complete response with all needed data
}
```

2. **Add Auto-Notification**:
```php
// Inside the method:
if ($activity->isCompleted()) {
    $notificationController->sendActivityCompletionNotification(
        $activity->id,
        Auth::id(),
        $activity->child_id,
        $activity->title
    );
}
```

3. **Use Transactions for Multi-step Operations**:
```php
DB::beginTransaction();
try {
    // Update activity
    // Create logs
    // Send notifications
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    // Handle error
}
```

## Benefits of This Approach in Daily Checklist

1. **Consistency**: Notifications are handled the same way regardless of client platform.
2. **Reduced Client Complexity**: Flutter app doesn't need complex notification creation logic.
3. **Better Performance**: Fewer network requests required from clients.
4. **Easier Maintenance**: Core logic is in one place, not duplicated across platforms.
5. **Better Error Handling**: Centralized logging and error management.

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/notifications` | GET | Get user's notifications |
| `/notifications` | POST | Create a notification |
| `/notifications/{id}` | GET | Get a specific notification |
| `/notifications/{id}` | PUT | Update a notification (mark as read) |
| `/notifications/{id}` | DELETE | Delete a notification |
| `/notifications/read-all` | PUT | Mark all notifications as read |
| `/notifications/register-token` | POST | Register a Firebase token |
| `/notifications/unread-count` | GET | Get count of unread notifications |
| `/notifications/new-plan` | POST | Notify about a new activity plan |
| `/notifications/activity-status` | POST | Notify about activity status change |
| `/notifications/system` | POST | Create system notifications |
| `/notifications/send-to-parents` | POST | Send notifications to specific parents | 