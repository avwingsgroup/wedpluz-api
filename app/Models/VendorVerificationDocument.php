<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorVerificationDocument extends Model
{
    protected $table = 'vendor_verification_documents';

    protected static string $idPrefix = 'vdoc';

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'upload_id');
    }
}
