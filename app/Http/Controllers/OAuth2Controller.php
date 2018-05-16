<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Discord\OAuth\Discord as Provider;
use Webpatser\Uuid\Uuid;
use App\ClientOAuth;
use GuzzleHttp\Client;
use League\OAuth2\Client\Token\AccessToken;

class OAuth2Controller extends Controller
{
    
    public function discord(Request $req){
        $provider = new Provider([
            'clientId' => Settings::discord_client_id(),
            'clientSecret' => Settings::discord_client_secret(),
            'redirectUri'  => Settings::discord_redirect_url(),
        ]);
        $code = $req->input('code');
        $state = $req->input('state');
        $type = $req->input('type');
        $panel_id = $req->input('id');
        $oauthToken = session(Settings::discord_session());
        // Check if OAuth Token found
        if($oauthToken != null){
            $oauthToken = ClientOAuth::where('id', $oauthToken);
            if($oauthToken == null) $oauthToken == new ClientOAuth;
            else if($oauthToken->count() > 0) $oauthToken = $oauthToken->get()[0];
            else $oauthToken = null;
            if($oauthToken != null && $oauthToken["client_id"] != "-1" && $oauthToken->ip == $req->ip()){
                $accessToken = $oauthToken["access_token"];
                $refreshToken = $oauthToken["refresh_token"];
                if($accessToken != "null"){
                    $user = null;
                    try{
                        $tokenData = new AccessToken(array(
                            "access_token" => $accessToken,
                        ));
                        $user = $provider->getResourceOwner($tokenData);
                    } catch (\Exception $e){}
                    if($user == null){
                        $accessToken = $provider->getAccessToken('refresh_token', [
                            'refresh_token' => $refreshToken,
                        ]);
                        $oauthToken["access_token"] = $accessToken->getToken();
                        $user = $provider->getResourceOwner($accessToken);
                        $oauthToken["client_id"] = $user->id;
                        
                    }
                    $panel = ($panel_id == null ? $oauthToken["panel_id"] : $panel_id);
                    $oauthToken["panel_id"] = $panel;
                    $oauthToken->save();
                    $userid = $user->id;                    
                    $url = route('panel.login', ["id" => $panel]);
                    return redirect($url);
                }
            }
        }
        if($type == null){
            $type == "client";
        }
        $id = $req->input('id');
        if($id == null && ($state == null || $code == null)){
            return view('error.panel')->with([
                "code" => 404,
                "title" => "Panel ID not found",
                "message" => "The given id in the url is not correct"
            ]);
        }
        // Check from the State
        if ($code == null && $state == null){
            $state = Uuid::generate();
            $url = $provider->getAuthorizationUrl() . '&state=' . $state;
            if($oauthToken == null) $clientoauth = new ClientOAuth;
            else {
                $clientoauth = $oauthToken;
                if($clientoauth->ip != $req->ip()) $clientoauth = new ClientOAuth;
            }
            $clientoauth->state = $state;
            $clientoauth["access_token"] = "null";
            $clientoauth["refresh_token"] = "null";
            $clientoauth["client_id"] = "-1";
            $clientoauth->type = "client";
            $clientoauth->code = "null";
            $clientoauth->ip = $req->ip();
            $clientoauth['panel_id'] = $id;
            $clientoauth->save();
            session([Settings::discord_session() => $clientoauth->id]);
            return redirect()->to($url);
        }
        if($state == null){
            return view('error.panel')->with([
                "code" => 203,
                "title" => "OAuth2 Error [0x0A]",
                "message" => "Something went wrong in the state in the oauth2"
            ]);
        }
        $clientoauth = null;
        $clientToken = null;
        if(strpos($state, ',')){
            $realstate = explode(',', $state);
            $realstate = $realstate[1];
            $clientoauth = $oauthToken;
            if($clientoauth->state != $realstate){
                return view('error.panel')->with([
                    "code" => 203,
                    "title" => "OAuth2 Error [0x0B]",
                    "message" => "Something went wrong in the state in the oauth2"
                ]);
            } 
        } else {
            return view('error.panel')->with([
                "code" => 203,
                "title" => "OAuth2 Error [0x0C]",
                "message" => "Something went wrong in the state in the oauth2"
            ]);
        }
        // Generate new OAuth Profile
        $tokenData = $provider->getAccessToken('authorization_code', [
            'code' => $code,
        ]);
        $accessToken = $tokenData->getToken();
        $refreshToken = $tokenData->getRefreshToken();
        $user = $provider->getResourceOwner($tokenData);
        $clientoauth["client_id"] = $user->id;
        $clientoauth["access_token"] = $accessToken;
        $clientoauth["refresh_token"] = $refreshToken;
        $clientoauth->save();
        session([Settings::discord_session() => $clientoauth->id]);
        $req = new Client([
            'base_uri' => "http://localhost:2040",
        ]);
        if($id == null) $id = $clientoauth['panel_id'];
        $res = $req->request("GET", '/check/client/'. $id . '/' . $clientoauth["client_id"]);
        $stream = $res->getBody();
        $response = json_decode($stream->getContents(), true);
        $panel = ($response['panel'] == "true" ? true : false);
        $client = ($response['client'] == "true" ? true : false);
        if($panel){
            if ($client){
                $clientoauth->save();
                if($clientoauth->type == "client"){
                    $url = route('panel.login', ["id" => $id]);
                    return redirect($url);
                } else if($clientoauth->type == "studio"){
                    return redirect(route('studio.login', ["id"=>$id]));
                }
            } else {
                $clientoauth->destroy();
                return view('error.panel')->with([
                    "code" => 404,
                    "title" => "Client Not found",
                    "message" => "We can't find you and access to you because you aren't in the server!"
                ]);
            }
        } else {
            $clientoauth->destroy();
            return view('error.panel')->with([
                "code" => 404,
                "title" => "Panel not found",
                "message" => "we didn't found the panel successfully"
            ]);
        }
    }

}
