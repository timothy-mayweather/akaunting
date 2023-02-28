<?php

namespace App\Http\Controllers\Install;

use App\Http\Requests\Install\Database as Request;
use App\Utilities\Installer;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class Database extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return view('install.database.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request $request
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $host     = $request['hostname'];
        $database = $request['database'];
        $username = $request['username'];
        $password = $request['password'];

        $connection = config('database.default','mysql');
        $port     = config("database.connections.$connection.port", '3306');

        // Check database connection
        if (!Installer::createDbTables($host, $port, $database, $username, $password)) {
            $response = [
                'status' => null,
                'success' => false,
                'error' => true,
                'message' => trans('install.error.connection'),
                'data' => null,
                'redirect' => null,
            ];
        }

        if (empty($response)) {
            $response['redirect'] = route('install.settings');
        }

        return response()->json($response);
    }
}
