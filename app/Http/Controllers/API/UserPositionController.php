<?php

namespace App\Http\Controllers\API;

use App\User;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\UserPosition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserPositionController extends Controller
{
   
    public function getUserPositions()
    { 
        $user = Auth::user();
        $userPositions = $user->positions;

        return response()->json([
            'user_id' => $user->id,
            'positions' => $userPositions,
        ]);

    }

    public function getLastUserPosition()
    { 
        $user = Auth::user();
        $userLastPosition = $user->positions->first();

        return response()->json([
            'user_id' => $user->id,
            'positions' => $userLastPosition,
        ]);
    }

    public function createUserPosition(Request $request)
    {
        $user = Auth::user();

        try {
            //Chequea los campos de entrada
            $campos = $request->validate([
                'lat' => ['required','numeric'],
                'lon' => ['required','numeric'],
                'alt' => ['required','numeric'],
            ]);

            //Crea la posición del usurio
            $user_position = UserPosition::create([
                'user_id' => $user->id,
                'lat' => $campos['lat'],
                'lon' => $campos['lon'],
                'alt' => $campos['alt']
            ]);
            
            if (!$user_position) {
                throw new \Error('No se pudo crear la posición actual del usuario.');
            }

            //Se lanza evento de Nuevo Mensaje
            //broadcast(new NewMessage($message));

            return response()->json([
                            'status' => 200,
                            'message' => 'Creación de la posición del usuario, realizada con éxito',
                            'user_position_id' => $user_position->id,
                            'lat' => $user_position->lat,
                            'lon' => $user_position->lon,
                            'alt' => $user_position->alt

            ]);
        }
        
        catch (QueryException $e) {
            throw new \Error('Error SQL');
        }

        catch (\Throwable $e) {
            $code = $e->getCode() ? $e->getCode() : 500;
            return response()->json([
                'status' => $e->getCode() ? $e->getCode() : 500,
                'message' => $e->getMessage()
            ], $code);
        }
    }
}
