<?php

/*
 * Only what differs from Livewire's own config; each key replaces Livewire's whole entry.
 */
return [

    /*
     * Livewire's default limit is 12 MB; WHSH broadcasts are MP3s of up to
     * hushfield.radio.upload_max_bytes. PHP's upload_max_filesize and
     * post_max_size must allow them too.
     */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:'.(50 * 1024)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],

];
