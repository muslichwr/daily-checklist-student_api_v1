# Flutter Client Implementation Guide for Backend-Heavy Architecture

This guide provides instructions for implementing the client side of the backend-heavy architecture in the Daily Checklist Flutter app.

## Overview

With the backend-heavy architecture, many operations that were previously handled client-side are now managed by the server. This simplifies the Flutter app's code and ensures consistent behavior across platforms.

## Client-Side Setup

### 1. FCM Token Registration

Update your Firebase initialization code to automatically register the device token with the backend:

```dart
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class FCMService {
  final String baseUrl = 'https://your-api-url.com/api';
  final FirebaseMessaging _firebaseMessaging = FirebaseMessaging.instance;
  final storage = const FlutterSecureStorage();

  Future<void> initialize() async {
    // Request permission
    await _firebaseMessaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    // Get token
    String? token = await _firebaseMessaging.getToken();
    if (token != null) {
      await _registerTokenWithBackend(token);
    }

    // Listen for token refresh
    FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
      _registerTokenWithBackend(newToken);
    });
  }

  Future<void> _registerTokenWithBackend(String token) async {
    // Get the auth token
    String? authToken = await storage.read(key: 'auth_token');
    if (authToken == null) return;

    try {
      // Get device info (optional)
      String deviceInfo = await _getDeviceInfo();

      // Register token with backend
      final response = await http.post(
        Uri.parse('$baseUrl/notifications/register-token'),
        headers: {
          'Authorization': 'Bearer $authToken',
          'Content-Type': 'application/json',
        },
        body: json.encode({
          'token': token,
          'device_info': deviceInfo,
        }),
      );

      if (response.statusCode != 200) {
        print('Failed to register FCM token: ${response.body}');
      }
    } catch (e) {
      print('Error registering FCM token: $e');
    }
  }

  Future<String> _getDeviceInfo() async {
    // Implement device info collection using device_info_plus package
    return 'Flutter Device';
  }
}
```

### 2. Simplifying NotificationProvider

Update your NotificationProvider to use the backend-heavy endpoints:

```dart
import 'package:flutter/material.dart';
import '../models/notification_model.dart';
import 'api_provider.dart';
import 'auth_provider.dart';

class NotificationProvider with ChangeNotifier {
  final ApiProvider _apiProvider;
  final AuthProvider _authProvider;
  List<NotificationModel> _notifications = [];
  bool _isLoading = false;
  String? _error;
  int _unreadCount = 0;

  List<NotificationModel> get notifications => _notifications;
  bool get isLoading => _isLoading;
  String? get error => _error;
  int get unreadCount => _unreadCount;

  NotificationProvider(this._apiProvider, this._authProvider) {
    // Listen to auth changes
    _authProvider.addListener(_onAuthChanged);
    
    // Fetch notifications if user is logged in
    if (_authProvider.user != null) {
      fetchNotifications();
      fetchUnreadCount();
    }
  }
  
  void _onAuthChanged() {
    final newUser = _authProvider.user;
    if (newUser != null) {
      fetchNotifications();
      fetchUnreadCount();
    } else {
      _notifications = [];
      _unreadCount = 0;
      notifyListeners();
    }
  }
  
  @override
  void dispose() {
    _authProvider.removeListener(_onAuthChanged);
    super.dispose();
  }

  Future<void> fetchNotifications({String? childId}) async {
    if (_authProvider.user == null) return;

    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      String endpoint = 'notifications';
      if (childId != null) {
        endpoint += '?child_id=$childId';
      }
      
      final data = await _apiProvider.get(endpoint);
      
      if (data != null) {
        _notifications = (data as List)
            .map((item) => NotificationModel.fromJson(item))
            .toList();
      } else {
        _notifications = [];
      }
    } catch (e) {
      _error = 'Failed to load notifications. Please try again.';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> fetchUnreadCount() async {
    if (_authProvider.user == null) return;

    try {
      final data = await _apiProvider.get('notifications/unread-count');
      if (data != null && data['unread_count'] != null) {
        _unreadCount = data['unread_count'];
        notifyListeners();
      }
    } catch (e) {
      print('Error fetching unread count: $e');
    }
  }

  Future<bool> markAsRead(String id) async {
    if (_authProvider.user == null) return false;

    try {
      final data = await _apiProvider.put('notifications/$id', {
        'is_read': true,
      });
      
      if (data != null) {
        // Update local state
        final index = _notifications.indexWhere((notification) => notification.id == id);
        if (index != -1) {
          _notifications[index] = NotificationModel.fromJson(data);
        }
        
        // Update unread count
        if (_unreadCount > 0) {
          _unreadCount--;
        }
        
        notifyListeners();
        return true;
      }
      
      return false;
    } catch (e) {
      return false;
    }
  }

  Future<bool> markAllAsRead() async {
    if (_authProvider.user == null) return false;

    try {
      await _apiProvider.put('notifications/read-all', {});
      
      // Update local state
      for (int i = 0; i < _notifications.length; i++) {
        if (!_notifications[i].isRead) {
          _notifications[i] = NotificationModel(
            id: _notifications[i].id,
            userId: _notifications[i].userId,
            title: _notifications[i].title,
            message: _notifications[i].message,
            type: _notifications[i].type,
            relatedId: _notifications[i].relatedId,
            childId: _notifications[i].childId,
            isRead: true,
            createdAt: _notifications[i].createdAt,
          );
        }
      }
      
      // Reset unread count
      _unreadCount = 0;
      
      notifyListeners();
      return true;
    } catch (e) {
      return false;
    }
  }

  Future<bool> deleteNotification(String id) async {
    if (_authProvider.user == null) return false;

    try {
      final data = await _apiProvider.delete('notifications/$id');
      
      if (data != null) {
        // Check if the notification was unread before removing
        bool wasUnread = false;
        final notification = _notifications.firstWhere(
          (notification) => notification.id == id,
          orElse: () => NotificationModel(
            id: '', userId: '', title: '', message: '', type: '', 
            isRead: true, createdAt: DateTime.now()
          ),
        );
        
        wasUnread = !notification.isRead;
        
        // Remove from local state
        _notifications.removeWhere((notification) => notification.id == id);
        
        // Update unread count if necessary
        if (wasUnread && _unreadCount > 0) {
          _unreadCount--;
        }
        
        notifyListeners();
        return true;
      }
      
      return false;
    } catch (e) {
      return false;
    }
  }
}
```

### 3. Handling Push Notifications

Configure your app to handle incoming push notifications:

```dart
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter/material.dart';
import 'dart:convert';

class NotificationHandler {
  final FlutterLocalNotificationsPlugin _flutterLocalNotificationsPlugin = 
      FlutterLocalNotificationsPlugin();
  final Function(Map<String, dynamic>) onNotificationTapped;

  NotificationHandler({required this.onNotificationTapped}) {
    _initLocalNotifications();
    _initFirebaseMessaging();
  }

  void _initLocalNotifications() async {
    // Initialize local notifications
    const AndroidInitializationSettings initializationSettingsAndroid =
        AndroidInitializationSettings('@mipmap/ic_launcher');
    const DarwinInitializationSettings initializationSettingsIOS =
        DarwinInitializationSettings();
    const InitializationSettings initializationSettings = InitializationSettings(
      android: initializationSettingsAndroid,
      iOS: initializationSettingsIOS,
    );

    await _flutterLocalNotificationsPlugin.initialize(
      initializationSettings,
      onDidReceiveNotificationResponse: (NotificationResponse response) {
        if (response.payload != null) {
          try {
            final payload = json.decode(response.payload!);
            onNotificationTapped(payload);
          } catch (e) {
            print('Error parsing notification payload: $e');
          }
        }
      },
    );
  }

  void _initFirebaseMessaging() {
    // Handle foreground messages
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      _showLocalNotification(message);
    });

    // Handle notification taps when app is in background
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      if (message.data.isNotEmpty) {
        onNotificationTapped(message.data);
      }
    });
  }

  Future<void> _showLocalNotification(RemoteMessage message) async {
    RemoteNotification? notification = message.notification;
    AndroidNotification? android = message.notification?.android;

    if (notification != null) {
      await _flutterLocalNotificationsPlugin.show(
        notification.hashCode,
        notification.title,
        notification.body,
        NotificationDetails(
          android: AndroidNotificationDetails(
            'daily_checklist_channel',
            'Daily Checklist Notifications',
            channelDescription: 'Notifications for Daily Checklist app',
            icon: android?.smallIcon ?? '@mipmap/ic_launcher',
            importance: Importance.high,
          ),
          iOS: const DarwinNotificationDetails(),
        ),
        payload: json.encode(message.data),
      );
    }
  }
}
```

### 4. Main App Integration

Update your main.dart to initialize everything properly:

```dart
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  
  // Initialize Firebase
  await Firebase.initializeApp(
    options: DefaultFirebaseOptions.currentPlatform,
  );
  
  // Set up FCM background message handler
  FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);
  
  await initializeDateFormatting(AppConfig.defaultLocale, null);
  
  // Initialize FCM service
  final fcmService = FCMService();
  await fcmService.initialize();
  
  runApp(const MyApp());
}
```

## Simplifications in UI Code

With the backend-heavy approach, your UI code becomes simpler:

### Before:
```dart
ElevatedButton(
  onPressed: () async {
    // Client-side logic to determine parents to notify
    final children = await childProvider.fetchChildren();
    final parentIds = children.map((c) => c.parentId).toList();

    // Client-side notification creation
    for (String parentId in parentIds) {
      await notificationProvider.createNotification(
        userId: parentId,
        title: 'New Plan',
        message: 'A new activity plan has been created',
        type: 'new_plan',
        relatedId: plan.id,
      );
    }
  },
  child: Text('Create Plan and Notify'),
),
```

### After:
```dart
ElevatedButton(
  onPressed: () async {
    // Server will handle creating notifications
    await planProvider.createPlan(planData);
  },
  child: Text('Create Plan'),
),
```

## Testing the Implementation

1. **Register FCM Token**: When a user logs in, their FCM token should automatically be sent to the backend.

2. **Check Notifications**: The UI should display notifications fetched from the server without manual notification creation.

3. **Verify Push Notifications**: When a teacher creates a new plan or updates activity status, parents should receive push notifications automatically.

## Troubleshooting

- **Notifications Not Showing**: Check if FCM token is correctly registered with the backend.
- **Push Notifications Not Working**: Verify that Firebase server key is correctly set in the backend `.env` file.
- **Unread Count Not Updating**: Make sure to call `fetchUnreadCount()` after user actions.

## Benefits

- **Simpler Code**: The Flutter app code is now simpler and more focused on UI rendering.
- **Consistent Behavior**: Notification logic is centralized on the backend, ensuring consistent behavior.
- **Reduced Network Traffic**: Fewer API calls are needed to manage notifications. 