<?php

namespace App\Http\Controllers\API;

use App\User;
use App\Models\Message;
use App\Events\NewMessage;
use App\Models\FileMessage;
use App\Models\TextMessage;
use App\Models\Conversation;
use App\Models\UserPosition;
use Illuminate\Http\Request;
use App\Models\PositionMessage;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;


class MessagesController extends Controller
{
    public function getConversations()
    {
        $user = Auth::user();
        $conversations = array();
        $active_users = array();
        $x = 0;

        $active_conversations = Conversation::where('user_id_1', $user->id)
                        ->orWhere('user_id_2', $user->id)
                        ->with('user_1')
                        ->with('user_2')
                        ->orderBy('updated_at', 'desc')
                        ->get();

        foreach ($active_conversations as $x => $conversation) {
            $ammount_messages_no_read = count($conversation->messages_no_read->where('receiver_id',$user->id));
            $user_dest= array();

            //Identifico el usuario DESTINO de la conversacion
            if ($active_conversations[$x]->user_1->id != $user->id) {
                $user_dest = [
                    "id" => $active_conversations[$x]->user_1->id,
                    "name" => $active_conversations[$x]->user_1->name,
                    "email" => $active_conversations[$x]->user_1->email,
                    "last_position" => $active_conversations[$x]->user_1->user_last_position
                ];
                $active_users[$x] = $active_conversations[$x]->user_1->id;
            }else{
                $user_dest = [
                    "id" => $active_conversations[$x]->user_2->id,
                    "name" => $active_conversations[$x]->user_2->name,
                    "email" => $active_conversations[$x]->user_2->email,
                    "last_position" => $active_conversations[$x]->user_2->user_last_position
                ];
                $active_users[$x] = $active_conversations[$x]->user_2->id;
            }

            $ammount_messages_no_read = count($conversation->messages_no_read->where('receiver_id',$user->id));

            $conversations[$x]['id']= $active_conversations[$x]->id;
            $conversations[$x]['user_dest']= $user_dest;
            $conversations[$x]['ammount_no_read']= $ammount_messages_no_read;
        }
        //Guardo el usuario logueado dentro de los usuarios con conversaci??n activa
        $active_users[$x+1] = $user->id;
        sort($active_users);

        //var_dump(count($active_users));

        if (count($active_users) > 1) { //Existe alguna conversacion activa
            $x++;
        }

        $inactive_users = User::whereNotIn('id', $active_users)
                        ->orderBy('name', 'asc')
                        ->get();


        foreach ($inactive_users as $inactive_user) {
            //Identifico el usuario DESTINO de la conversacion
            $user_dest = [
                "id" => $inactive_user->id,
                "name" => $inactive_user->name,
                "email" => $inactive_user->email,
                "last_position" => $inactive_user->user_last_position
            ];

            $conversations[$x]['id']= 0;
            $conversations[$x]['user_dest']= $user_dest;
            $conversations[$x]['ammount_no_read']= 0;

            $x++;
        }

        return response()->json([
            'user_origin' => $user->id,
            'conversations' => $conversations,
        ]);
    }
    public function getMessagesFromUserOLD(Conversation $conversation)
    {
        $user = Auth::user();
        $messages = [];

        if ($user->id !== $conversation->user_id_1 && $user->id !== $conversation->user_id_2) {
            throw new AccessDeniedHttpException(__('No existe la conversaci??n para el usuario.'));
        }

        //Devuelve los mensajes de una Conversacion y pone como READ todos los mjes no leidos

        $messages = Message::where('conversation_id', $conversation->id)
                            ->orderBy('created_at', 'asc')
                            ->paginate(10);

        Message::where('receiver_id',$user->id)
                ->where('read_at',NULL)
                ->update(['read_at' => now()]);

        return response()->json([
            'user_origin' => $user->id,
            'messages' => $messages,
        ]);

    }
    public function getMessagesFromConversation($request)
    {
        //Se env??a el id de la conversaci??n por $request porque puede enviarse id=0 y sino rebotar??a porque no existe en la BD
        $user = Auth::user();
        $conversation_id = intval($request);

        try {
            // var_dump($conversation_id);
            // var_dump($request);

            if ($conversation_id > 0) {
                //Chequear que exista la conversacion y pertenezca al usuario logueado
                $conversation = Conversation::where('id', $conversation_id)
                                          ->first();

                if (!$conversation) {
                    throw new AccessDeniedHttpException(__('No existe la conversaci??n.'));
                } else { //Existe el id de conversacion PERO NO pertenece al usuario logueado
                    if (($user->id !== $conversation->user_id_1 && $user->id !== $conversation->user_id_2)) {
                        throw new AccessDeniedHttpException(__('No existe la conversaci??n para el usuario.'));
                    }
                }

                //Devuelve los mensajes de una Conversacion y pone como READ todos los mjes no leidos
                $messages = Message::where('conversation_id', $conversation->id)
                                    ->orderBy('created_at', 'desc')
                                    ->paginate(10);

                Message::where('conversation_id', $conversation->id)
                    ->where('receiver_id', $user->id)
                    ->where('read_at', null)
                    ->update(['read_at' => now()]);
            }else{
                $messages = Message::where('conversation_id', 0)
                                 ->paginate(10);
            }

            return response()->json([
                'user_origin' => $user->id,
                'messages' => $messages,
            ]);
        }

        catch (QueryException $e) {
            throw new \Error('Error SQL');
        }

        catch (\Throwable $e) {
            DB::rollBack();

            $code = $e->getCode() ? $e->getCode() : 500;
            return response()->json([
                'status' => $e->getCode() ? $e->getCode() : 500,
                'message' => $e->getMessage()
            ], $code);
        }

    }
    public function createTextMessage(Request $request)
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {

            //Chequea los campos de entrada
            $campos = $request->validate([
                'message' => ['required','string', 'max:255'],
                'receiver_id' => ['required','integer', 'exists:users,id'],
            ]);

            $user_dest = User::where('id',$campos['receiver_id'])
                        ->first();
            if (!$user_dest || $user->id == $campos['receiver_id']) {
                throw new AccessDeniedHttpException(__('No se puede enviar mensaje al usuario destino.'));
            }
            $conversation = Conversation::where(function($q) use ($user, $campos){
                                            $q->where('user_id_1', $user->id);
                                            $q->where('user_id_2', $campos['receiver_id']);
                                        })
                                        ->orWhere(function($q) use ($user, $campos){
                                            $q->where('user_id_2', $user->id);
                                            $q->where('user_id_1', $campos['receiver_id']);
                                        })
                                        ->first();

            if (!$conversation){ //La conversacion NO existe, se crea antes del mensaje
                $conversation = Conversation::create([
                    'user_id_1' => $user->id,
                    'user_id_2' => $campos['receiver_id'],
                    ]);

                if (!$conversation) {
                   throw new \Error('No se pudo crear la conversaci??n.');
                }
            }

            //Crea el mje de texto
            $text_message = TextMessage::create([
                'text' => $campos['message']
              ]);

              if (!$text_message) {
                throw new \Error('No se pudo crear el mensaje de texto.');
              }

            // Crea el mensaje y lo asocia a la conversacion
            $message = Message::create([
                'sender_id' => $user->id,
                'receiver_id' => (int) $campos['receiver_id'],
                'conversation_id' => $conversation->id,
                'message_type' => get_class($text_message),
                'message_id' => $text_message->id,
            ]);

            if (!$message) {
                throw new \Error('No se pudo crear el mensaje.');
            }

            Conversation::where('id',$conversation->id)
                          ->update(['updated_at' => now()]);

            DB::commit();

            //Se lanza evento de Nuevo Mensaje
            broadcast(new NewMessage($message));

            return response()->json([
                'status' => 200,
                'message' => 'Creaci??n del mensaje de TEXTO realizada con ??xito',
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                "sender_id" => $message->sender_id,
                "receiver_id" => $message->receiver_id,
                "read_at" => $message->read_at,
                'message_created' => $message->message
            ]);

        }

        catch (QueryException $e) {
            throw new \Error('Error SQL');
        }

        catch (\Throwable $e) {
            DB::rollBack();

            $code = $e->getCode() ? $e->getCode() : 500;
            return response()->json([
                'status' => $e->getCode() ? $e->getCode() : 500,
                'message' => $e->getMessage()
            ], $code);
        }
    }
    public function createFileMessage(Request $request)
    {
        $user = Auth::user();

        $files = array();

        $validator = Validator::make(
            $request->all(),
            [
                'receiver_id' => ['required','integer'],
                'file' => ['required', 'array'],
                'file.*' => ['file','required', 'mimes:doc,pdf,docx,txt,zip,jpeg,png,bmp,xls,xlsx,mov,qt,mp4,mp3,m4a' ,'max:10240'],
                'description' => ['sometimes', 'array'],
                'description.*' => ['nullable', 'string'],
            ],[
                'file.*.mimes' => __('Los archivos s??lo pueden ser doc,pdf,docx,txt,zip,jpeg,png,bmp,xls,xlsx,mov,qt,mp4,mp3,m4a'),
                'file.*.max' => __('Cada archivo no puede ser mayor a 10MB'),
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $campos = $request;

            $user_dest = User::where('id',$campos['receiver_id'])
                        ->first();

            if (!$user_dest || $user->id == $campos['receiver_id']) {
                throw new AccessDeniedHttpException(__('No se puede enviar mensaje al usuario destino.'));
            }

            $conversation = Conversation::where(function($q) use ($user, $campos){
                                            $q->where('user_id_1', $user->id);
                                            $q->where('user_id_2', $campos['receiver_id']);
                                        })
                                        ->orWhere(function($q) use ($user, $campos){
                                            $q->where('user_id_2', $user->id);
                                            $q->where('user_id_1', $campos['receiver_id']);
                                        })
                                        ->first();

            if (!$conversation){ //La conversacion NO existe, se crea antes del mensaje
                $conversation = Conversation::create([
                    'user_id_1' => $user->id,
                    'user_id_2' => $campos['receiver_id'],
                    ]);

                if (!$conversation) {
                   throw new \Error('No se pudo crear la conversaci??n.');
                }
            }

            //Crea el mje de file
            $file_message = FileMessage::create();

            if (!$file_message) {
                throw new \Error('No se pudo crear el file_message.');
            }

            foreach ($request->file('file') as $index => $file) {
                $original_filename = $file->getClientOriginalName();

                $name = explode('.', $original_filename);
                $cant = count($name);

                $filename  = "";

                //Concateno si hubieran varios . s??lo me interesa separar el ??ltimo
                for($i=0;$i<=$cant-2;$i++){

                    if($i!=0){
                        $filename  = $filename . '.';
                    }
                    $filename  = $filename . $name[$i];
                }

                //$filename  = $name[0] .'_' . time() . '.' . $name[1];
                $filename  = $filename .'_' . time() . '.' . $name[$cant-1];

                $file->storeAs('public/files', $filename);

                $files[] = [
                    'file' => 'files/' . $filename,
                    'original_file' => $original_filename,
                    'description' => isset($campos['description'][$index]) ? $campos['description'][$index] : $file->getClientOriginalName(),
                ];
            }

            //VER c??mo manejar el error en BD!!
            $attachments = $file_message->files()->createMany($files);

            if (!$attachments) {
                throw new \Error('No se pudo crear el attachment.');
            }

            // Crea el mensaje y lo asocia a la conversacion
            $message = Message::create([
                'sender_id' => $user->id,
                'receiver_id' => (int) $campos['receiver_id'],
                'conversation_id' => $conversation->id,
                'message_type' => get_class($file_message),
                'message_id' => $file_message->id
            ]);

            if (!$message) {
                throw new \Error('No se pudo crear el mensaje.');
            }

            Conversation::where('id',$conversation->id)
                         ->update(['updated_at' => now()]);

            DB::commit();

            //Se lanza evento de Nuevo Mensaje
            broadcast(new NewMessage($message));

            return response()->json([
                'status' => 200,
                'message' => 'Creaci??n del mensaje de FILE realizada con ??xito',
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                "sender_id" => $message->sender_id,
                "receiver_id" => $message->receiver_id,
                "read_at" => $message->read_at,
                'message_created' => $message->message
            ]);

        }

        catch (QueryException $e) {
            throw new \Error('Error SQL');
        }

        catch (\Throwable $e) {

            DB::rollBack();
            if (count($files) > 0) {
                foreach ($files as $file) {
                $filename = $file['file'];
                Storage::disk('public')->delete($filename);
                }
            }

            if ($files) {
                $file_message->files()->delete();
            }

            if(isset($message)) {
                $message->delete();
                $file_message->delete();
            }

            $code = $e->getCode() ? $e->getCode() : 500;
            // return response()->json([
            //     'status' => $e->getCode() ? $e->getCode() : 500,
            //     'message' => $e->getMessage()

            // ], $code);
            return response()->json(['errors' => $e->getMessage()], 400);


        }
    }
    public function createPositionMessage(Request $request)
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {

            //Chequea los campos de entrada
            $campos = $request->validate([
                'lat' => ['required','numeric'],
                'lon' => ['required','numeric'],
                'alt' => ['required','numeric'],
                'receiver_id' => ['required','integer','exists:users,id']
            ]);

            if ($user->id == $campos['receiver_id']) {
                throw new AccessDeniedHttpException(__('No se puede enviar mensaje al usuario destino.'));
            }
            $conversation = Conversation::where(function($q) use ($user, $campos){
                                            $q->where('user_id_1', $user->id);
                                            $q->where('user_id_2', $campos['receiver_id']);
                                        })
                                        ->orWhere(function($q) use ($user, $campos){
                                            $q->where('user_id_2', $user->id);
                                            $q->where('user_id_1', $campos['receiver_id']);
                                        })
                                        ->first();

            if (!$conversation){ //La conversacion NO existe, se crea antes del mensaje
                $conversation = Conversation::create([
                    'user_id_1' => $user->id,
                    'user_id_2' => $campos['receiver_id'],
                    ]);

                if (!$conversation) {
                   throw new \Error('No se pudo crear la conversaci??n.');
                }
            }

            //Crea el mje de posici??n
            $position_message = PositionMessage::create([
                'lat' => $campos['lat'],
                'lon' => $campos['lon'],
                'alt' => $campos['alt']
              ]);

              if (!$position_message) {
                throw new \Error('No se pudo crear el mensaje de posici??n.');
              }

            // Crea el mensaje y lo asocia a la conversacion
            $message = Message::create([
                'sender_id' => $user->id,
                'receiver_id' => (int) $campos['receiver_id'],
                'conversation_id' => $conversation->id,
                'message_type' => get_class($position_message),
                'message_id' => $position_message->id,
            ]);

            if (!$message) {
                throw new \Error('No se pudo crear el mensaje.');
            }

            Conversation::where('id',$conversation->id)
                          ->update(['updated_at' => now()]);

            //Con las coordenadas enviadas en el mensaje, reporta tambi??n la posici??n del usuario
            $user_position = UserPosition::create([
                'user_id' => $user->id,
                'lat' => $campos['lat'],
                'lon' => $campos['lon'],
                'alt' => $campos['alt']
            ]);
            
            if (!$user_position) {
                throw new \Error('No se pudo crear la posici??n actual del usuario.');
            }

            DB::commit();

            //Se lanza evento de Nuevo Mensaje
            broadcast(new NewMessage($message));

            return response()->json([
                'status' => 200,
                'message' => 'Creaci??n del mensaje de POSICI??N realizada con ??xito',
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                "sender_id" => $message->sender_id,
                "receiver_id" => $message->receiver_id,
                "read_at" => $message->read_at,
                'message_created' => $message->message
            ]);

        }

        catch (QueryException $e) {
            throw new \Error('Error SQL');
        }

        catch (\Throwable $e) {
            DB::rollBack();

            $code = $e->getCode() ? $e->getCode() : 500;
            return response()->json([
                'status' => $e->getCode() ? $e->getCode() : 500,
                'message' => $e->getMessage()
            ], $code);
        }
    }
}
