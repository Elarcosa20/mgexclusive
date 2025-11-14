<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class ImageController extends Controller
{
    public function placeholder($width, $height)
    {
        $width = min($width, 2000);
        $height = min($height, 2000);
        
        // Create image
        $image = imagecreate($width, $height);
        
        // Background color (light gray)
        $bgColor = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $bgColor);
        
        // Text color (dark gray)
        $textColor = imagecolorallocate($image, 120, 120, 120);
        
        // Get text from query parameter
        $text = request('text') ?: "{$width}×{$height}";
        
        // Calculate text position (centered)
        $fontSize = 4;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        
        // Add text
        imagestring($image, $fontSize, $x, $y, $text, $textColor);
        
        // Output image
        header('Content-Type: image/png');
        imagepng($image);
        imagedestroy($image);
        
        exit;
    }

    public function serveStorageImage($path)
    {
        $filePath = storage_path('app/public/' . $path);
        
        if (!file_exists($filePath)) {
            return redirect("/api/placeholder/400/300?text=Image+Not+Found");
        }
        
        $file = new \Symfony\Component\HttpFoundation\File\File($filePath);
        $type = $file->getMimeType();
        
        $response = response(file_get_contents($filePath), 200)
            ->header('Content-Type', $type);
        
        return $response;
    }

    public function serveChatImage($filename)
    {
        $filePath = storage_path('app/public/chat-images/' . $filename);
        
        if (!file_exists($filePath)) {
            return redirect("/api/placeholder/400/300?text=Chat+Image+Not+Found");
        }
        
        $file = new \Symfony\Component\HttpFoundation\File\File($filePath);
        $type = $file->getMimeType();
        
        $response = response(file_get_contents($filePath), 200)
            ->header('Content-Type', $type);
        
        return $response;
    }

    public function uploadChatImages(Request $request)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240'
        ]);

        $uploadedUrls = [];
        
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('chat-images', 'public');
                $uploadedUrls[] = Storage::url($path);
            }
        }
        
        return response()->json([
            'success' => true,
            'imageUrls' => $uploadedUrls
        ]);
    }

    public function uploadProposalImages(Request $request)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp,avif|max:10240'
        ]);

        $uploadedUrls = [];
        
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('proposal-images', 'public');
                $uploadedUrls[] = Storage::url($path);
            }
        }
        
        return response()->json([
            'success' => true,
            'imageUrls' => $uploadedUrls
        ]);
    }
}