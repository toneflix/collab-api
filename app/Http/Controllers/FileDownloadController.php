<?php

namespace App\Http\Controllers;

use App\Services\Storage\DownloadLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a stored file as a download, from a signed link made by DownloadLink.
 */
class FileDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $valid = $request->validate([
            'collection' => ['required', 'string', Rule::in(DownloadLink::COLLECTIONS)],
            'name' => ['required', 'string'],
            'filename' => ['required', 'string', 'max:200'],
        ]);

        return DownloadLink::stream($valid['collection'], $valid['name'], $valid['filename']);
    }
}
