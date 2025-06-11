# Testing the Daily Checklist API with Postman

This guide will help you test the API endpoints using Postman.

## Setting Up Postman

1. Import the Postman collection:
   - Open Postman
   - Click "Import" button
   - Select the `daily_checklist_api.postman_collection.json` file from the project root directory

2. Set up environment variables:
   - Create a new environment (click gear icon → "Add")
   - Name it "Daily Checklist API"
   - Add the following variables:
     - `base_url`: `http://127.0.0.1:8000/api`
     - `token`: Leave this empty (it will be filled automatically after login)

3. Select the "Daily Checklist API" environment from the dropdown in the upper right corner

## Testing Workflow

### Authentication

1. **Register a Teacher Account**
   - Use the "Register Teacher" request in the Authentication folder
   - The default JSON body includes:
     ```json
     {
         "name": "Teacher Name",
         "email": "teacher@example.com",
         "password": "password123"
     }
     ```
   - Execute the request to create your first teacher account
   - The token will be automatically returned and can be saved to your environment

2. **Login**
   - Use the "Login" request with the teacher credentials
   - The token will be automatically saved to the environment variable
   - All subsequent requests will use this token for authentication

3. **Verify Current User**
   - Use "Get Current User" request to confirm you're authenticated

4. **Register a Parent Account**
   - As a teacher, you can register a parent account using the "Register Parent" request in the Authentication folder
   - You MUST be logged in as a teacher and include the auth token in headers
   - The JSON body should include:
     ```json
     {
         "name": "Parent Name",
         "email": "parent@example.com",
         "password": "password123",
         "phone_number": "081234567890"
     }
     ```

### User Management

5. **List and Manage Users**
   - Use the requests in the "Users" folder to:
     - List all users
     - Get user details
     - Update user information
     - Deactivate a user (soft delete)

### Child Management

6. **Create a Child**
   - Use "Create Child" request with proper parent_id
   - Example:
     ```json
     {
         "name": "Child Name",
         "birth_date": "2018-01-01",
         "gender": "male",
         "parent_id": 2
     }
     ```

7. **List and Manage Children**
   - Use the other requests in the "Children" folder

### Activity Management

8. **Create an Activity**
   - Use "Create Activity" request to create an activity with steps
   - Example:
     ```json
     {
         "title": "Morning Routine",
         "description": "Morning activities for the child",
         "environment": "Both",
         "difficulty": "Medium",
         "min_age": 3,
         "max_age": 6,
         "next_activity_id": null,
         "steps": [
             "Brush teeth",
             "Take a bath",
             "Get dressed"
         ]
     }
     ```

9. **Add Custom Steps**
   - Use "Add Custom Steps" request to add more steps to an activity
   - Example:
     ```json
     {
         "steps": [
             "New custom step 1", 
             "New custom step 2"
         ]
     }
     ```

### Checklist Management

10. **Create a Checklist**
    - Use "Create Checklist" request to assign an activity to a child
    - Example:
      ```json
      {
          "child_id": 1,
          "activity_id": 1,
          "date": "2023-07-20"
      }
      ```

11. **Update Checklist Status**
    - Use "Update Checklist" request to update the status of each step
    - Example:
      ```json
      {
          "step_statuses": [
              {
                  "step_id": 1,
                  "status": "completed"
              },
              {
                  "step_id": 2,
                  "status": "in_progress"
              }
          ]
      }
      ```

12. **Add Observations**
    - Use the "Add Home Observation" or "Add School Observation" requests

### Notification Management

13. **Create a Notification**
    - Use "Create Notification" request to send a notification to a user
    - Example:
      ```json
      {
          "title": "New Checklist Assigned",
          "message": "A new checklist has been assigned to your child",
          "recipient_id": 2,
          "related_type": "checklist",
          "related_id": 1
      }
      ```

14. **Manage Notifications**
    - Use the other requests in the "Notifications" folder to:
      - List notifications
      - Mark notifications as read
      - Delete notifications

### Plan Management

15. **Create a Plan**
    - Use "Create Plan" request to create a plan with activities
    - Example:
      ```json
      {
          "type": "weekly",
          "start_date": "2023-07-20",
          "child_id": 1,
          "activities": [
              {
                  "activity_id": 1,
                  "scheduled_date": "2023-07-20",
                  "scheduled_time": "08:00",
                  "reminder": true
              },
              {
                  "activity_id": 2,
                  "scheduled_date": "2023-07-21",
                  "scheduled_time": "09:00",
                  "reminder": true
              }
          ]
      }
      ```

16. **List Plans**
    - Use "List Plans" request to view all plans
    - You can filter by child_id or type (weekly/daily)

17. **Update Activity Status**
    - Use "Update Activity Status" request to mark an activity as completed
    - Example:
      ```json
      {
          "completed": true
      }
      ```

18. **Update Plan**
    - Use "Update Plan" request to modify an existing plan
    - You can update plan type and activities
    - Example:
      ```json
      {
          "type": "daily",
          "activities": [
              {
                  "id": 1,
                  "activity_id": 1,
                  "scheduled_date": "2023-07-20",
                  "scheduled_time": "08:30",
                  "reminder": false
              }
          ]
      }
      ```

## Tips for Testing

1. **Parent vs. Teacher Accounts**:
   - Some operations are restricted based on user role
   - Test with both accounts to verify permission checks

2. **Test Error Cases**:
   - Try accessing resources you don't own
   - Submit invalid data to test validation

3. **Authentication Token**:
   - If you get 401 errors, your token may have expired
   - Simply login again to refresh the token

## API Endpoints Reference

The API has the following endpoint groups:

1. **Authentication**
   - `POST /api/register` - Register a new teacher account (public)
   - `POST /api/login` - Login and get token (public)
   - `POST /api/logout` - Logout (revoke token) (protected)
   - `GET /api/user` - Get current user (protected)
   - `POST /api/change-password` - Change password (protected)
   - `POST /api/register-parent` - Register a parent account (protected, teacher only)

2. **Users**
   - `GET /api/users` - List users
   - `GET /api/users/{id}` - Get user
   - `PUT /api/users/{id}` - Update user
   - `DELETE /api/users/{id}` - Deactivate user

3. **Children**
   - `GET /api/children` - List children
   - `GET /api/children/{id}` - Get child
   - `POST /api/children` - Create child
   - `PUT /api/children/{id}` - Update child
   - `DELETE /api/children/{id}` - Delete child

4. **Activities**
   - `GET /api/activities` - List activities
   - `GET /api/activities/{id}` - Get activity
   - `POST /api/activities` - Create activity
   - `PUT /api/activities/{id}` - Update activity
   - `DELETE /api/activities/{id}` - Delete activity
   - `POST /api/activities/{activity}/steps` - Add custom steps

5. **Checklists**
   - `GET /api/checklists` - List checklists
   - `GET /api/checklists/{id}` - Get checklist
   - `POST /api/checklists` - Create checklist
   - `PUT /api/checklists/{id}` - Update checklist
   - `DELETE /api/checklists/{id}` - Delete checklist
   - `POST /api/checklists/{checklist}/home-observation` - Add home observation
   - `POST /api/checklists/{checklist}/school-observation` - Add school observation
   - `PUT /api/checklists/{checklist}/status` - Update status

6. **Notifications**
   - `GET /api/notifications` - List notifications
   - `GET /api/notifications/{id}` - Get notification
   - `POST /api/notifications` - Create notification
   - `PUT /api/notifications/{id}` - Update notification
   - `DELETE /api/notifications/{id}` - Delete notification
   - `PUT /api/notifications/read-all` - Mark all as read 

7. **Plans**
   - `GET /api/plans` - List plans
   - `GET /api/plans/{id}` - Get plan
   - `POST /api/plans` - Create plan
   - `PUT /api/plans/{id}` - Update plan
   - `DELETE /api/plans/{id}` - Delete plan
   - `PUT /api/planned-activities/{id}/status` - Update activity status 