<?php

declare(strict_types=1);

/*
 * English messages for App\Shared\Files (message_key = files.<name>). Every key has an Amharic twin in lang/am/files.php (rule 9);
 * tests/Modules/Shared/FilesLangTest.php keeps them in step with FilesError::KEYS.
 */
return [
    'not_an_image' => 'This file is not a picture. Take a photo or choose a JPEG, PNG or WebP picture.',
    'truncated' => 'The picture is incomplete or damaged. Take it again.',
    'too_many_pixels' => 'The picture is too large. Take it again at a lower resolution.',
    'too_large' => 'The file is too big to upload. Take the photo again or choose a smaller one.',
    'unsupported_type' => 'This kind of picture is not accepted. Use JPEG, PNG or WebP.',
    'unavailable' => 'Photo upload is not available on this server right now. Ask an administrator to check it.',
    'processing_failed' => 'The photo could not be prepared. Try again.',
    'invalid_signature' => 'This link is not valid or has expired.',
    'missing' => 'No picture was sent. Take a photo or choose a picture and try again.',
    'attached' => 'This photo is already attached to a payment and cannot be deleted.',
];
