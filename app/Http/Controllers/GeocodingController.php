<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingController extends Controller
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * Proxies the geocoding request to Nominatim.
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $format = $request->input('format', 'json');
        $limit = $request->input('limit', 5);

        Log::info('Geocoding Request:', ['query' => $query, 'limit' => $limit]);

        if (!$query) {
            return response()->json(['error' => 'Query is required'], 400);
        }

        try {
            // Se recomienda enviar un User-Agent según las políticas de Nominatim
            $response = Http::withHeaders([
                'User-Agent' => 'RentUS-Application/1.0 (miguelcamilok@gmail.com)',
                'Accept-Language' => 'es'
            ])
            ->timeout(10)
            ->get(self::NOMINATIM_URL, [
                'q' => $query,
                'format' => $format,
                'limit' => $limit,
                'addressdetails' => 1,
            ]);

            if ($response->failed()) {
                Log::error('Nominatim request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['error' => 'Geocoding service failure'], 502);
            }

            return response()->json($response->json());

        } catch (\Exception $e) {
            Log::error('Geocoding error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error during geocoding'], 500);
        }
    }
}
