<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Store a newly uploaded photo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // max 5MB
            'type' => 'required|string|in:activity,profile',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Get the file from the request
            $file = $request->file('photo');
            
            // Generate a unique filename
            $filename = Str::random(20) . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Store the file in the appropriate directory based on type
            $path = $request->type === 'activity' ? 'activities' : 'profiles';
            
            // Store the file directly in the public/storage directory
            $file->move(public_path("storage/$path"), $filename);
            
            // Generate the URL for the file (relative to domain)
            $url = "/storage/$path/$filename";
            
            Log::info('Photo uploaded successfully', [
                'user_id' => Auth::id(),
                'path' => "storage/$path/$filename",
                'url' => $url,
            ]);
            
            return response()->json([
                'success' => true,
                'url' => $url,
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading photo: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage(),
            ], 500);
        }
    }
} 