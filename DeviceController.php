<?php

namespace App\Http\Controllers\API\v1;

use CBWCloud\Influx\Influx;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use CBWCloud\Models\Device;
use CBWCloud\Models\User;
use CBWCloud\Models\SimCard;
use CBWCloud\Models\DeviceSeat;
use CBWCloud\Models\ModelNumber;
use CBWCloud\Models\Plan;
use CBWCloud\Models\Feature;
use App\CommonClasses\RemoteDeviceAccess;
use App\CommonClasses\CellDataProvider;
use App\CommonClasses\AirVantage;
use App\CommonClasses\Telit;
use Illuminate\Support\Facades\DB;
use App\Account;
use Exception;
use \DateTime;
use \DateInterval;
use \DateTimeZone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\CommonClasses\AccountAccess;
use App\CommonClasses\RedisClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\CommonClasses\CellDataProviderFactory;

class DeviceController extends Controller
{
    public $CELL_ID_LENGTH = 13;

    use AccountAccess;

    public function __construct()
    {        
        $this->middleware(function($request,$next)
        {
            $devices = array();                
            
            if($request->DeviceId){ // just one
               $device = Device::where('device_id','=',$request->DeviceId)->firstOrFail();  // if not found this will responde 422
               array_push($devices, $device);
            } else if ($request->devices) { // multiple
                foreach($request->devices as $body) {
                    $deviceId = $body[0]; // get the value ID
                    $device = Device::where('device_id','=',$deviceId)->firstOrFail();  // if not found this will responde 422
                    array_push($devices, $device);
                }
            }
            $request->merge(['devices' => $devices]);  // can't pass url params through next. merge it into the request object
            return app(\App\Http\Middleware\VerifyDeviceState::class)->handle($request, $next); 
        })->only([
            'remoteAccess',
            'show',
            'update',
            'dataUsage',
            'checkActiveStatus',
            'checkDeviceActivationOperation',
            'performCellAction',
            'activateCell'
        ]);        
    }      

    // ...

    // this for manually adding a device
    // see CertController.php for where the device get added automatically when they request their certificates for the first time.
    public function add($AccountId = 'self', Request $request)
    {
       //error_log("DeviceController:add");
        $validatedData = $request->validate([
            'device_name' => 'required',
            'serial_number' => 'required|size:12',
            'cell_id' => 'required'
        ]);
        $sn = strtoupper($request->serial_number);

        $account = $this->getAccount($AccountId, $request);
        if ($account == null) {
            return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
        }
        // see if we can find the device already connected by looking up the serial_number
        if (DB::table('devices')->where('serial_number', $sn)->exists()) {
            return response()->json(['message' => "A device with serial number $sn already exists", 'errors' => ['serial_number' => "A device with serial number $sn already exists, check fields and contact tech support if problem persists"]], 422);
        }


        // Check if the serial number already exists in the database.
        if (strlen($request->cell_id) > $this->CELL_ID_LENGTH) {
            // the cell Id is actually the ICCID
            $SIM_cards = DB::table('SIM_cards')->where('serial_number', $sn)->where('iccid', '=', $request->cell_id)->first();
        }
        else {
            $SIM_cards = DB::table('SIM_cards')->where('serial_number', $sn)->where('cell_id', '=', $request->cell_id)->first();
        }
        if ($SIM_cards == null) {
            return response()->json(['message' => "Could not lookup Cell ID $request->cell_id. Double check cell ID and try again.", 'errors' => ['cell_id' => "Could not lookup Cell ID $request->cell_id. Double check cell ID and try again."]], 422);
        }
        $device = new Device();
        do {
            $device_id = mt_rand(0, 4294967295);
        } while (Device::where('id', '=', $device_id)->count() > 0);


        $device->device_id = $device_id;
        $device->account_id = $account->id;
        $device->serial_number = $sn;
        $device->modelNumber_id = $SIM_cards->model_number_id;
        $device->name = $request->device_name;
        $device->iccid = $SIM_cards->iccid;
        $device->air_vantage_uid = $SIM_cards->air_vantage_uid;
        $device->connected = 0;
        
        $request->merge(['devices' => $device]); // We'll hit the edit page right after this function, so we'll need this to get the provider.
        // Log::channel('testing')->info("provider=" . $SIM_cards->service_provider);
        $cellDataProvider = CellDataProviderFactory::create($SIM_cards->service_provider);

        if (!empty($device->iccid)) {
            $lifeCycleState = $cellDataProvider->getLifeCycleState($device);
            if (($lifeCycleState == 'ACTIVE') || ($lifeCycleState == 'TEST_READY')) {
                $device->activated = 1;
                $device->activation_operation_id = ""; // clear the operation id for the activation since the sim is now active.
            } else {
                $device->activated = 0;
            }


            DB::table('SIM_cards')
                ->where('serial_number', $device->serial_number)->where('iccid', $device->iccid)
                ->update(['air_vantage_uid' => $device->air_vantage_uid, 'cell_active' => $device->activated, 'account_id' => $account->id]);

            $account['device_seats'] = $account->device_seats + 1;
        }


        DB::beginTransaction();
        try {
            $account->save();
            $advanced_plan = Plan::where('log_retention',90)->first();
            DB::table("device_seats")->insert(['log_retention' => 90, 'account_id' => $account->id, 'occupied' => false, 'created_at' =>  Carbon::now(), 'plan_id'=>$advanced_plan->id]);
            $dev_seat = DB::table('device_seats')->where('account_id', $account->id)->where('log_retention',90)->where('occupied', false)->first();
            DB::table('device_seats')->where('id', $dev_seat->id)->update(['occupied' => true,'updated_at'=> Carbon::now()]);
            $device->device_seat_id = $dev_seat->id;
            $device->save();
            DB::commit();
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            return response()->json(['message' => 'Error saving device'], 422);
        }
        return response()->json(['message' => 'success', 'device' => $device]);
    }

    // ...

    public function update($AccountId = 'self', Request $request, $DeviceId, CellDataProvider $cellDataProvider)
    {
        error_log("DeviceController:update");
        $account = $this->getAccount($AccountId, $request);
        if ($account == null) {
            return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
        }

        $device = $account->devices->where('device_id', $DeviceId)->first();
        $device_seat = DB::table('device_seats')->where('id', $device->device_seat_id)->first();

        if ($device && $device_seat) {
            // if this is a cell device see if it's activated
            // if not, then check with air-vantage to see if it's activated
            if (!empty($device->iccid)) {
                if ($device->activated == 0) {
                    $device->activated = $this->checkDeviceActivationOperation($device, $cellDataProvider);
                    if ($device->activated == 1)
                        $device->activation_operation_id = ""; // clear the operation id for the activation since the sim is now active.
                }
            }
            if ($request->input('control_password')) {
                if ($request->input('control_password') == "********")
                    $device->control_password = "";
                else
                    $device->control_password = $request->input('control_password');
            }
            if ($request->input('setup_password')) {
                if ($request->input('setup_password') == "********")
                    $device->setup_password = "";
                else
                    $device->setup_password = $request->input('setup_password');
            }

            //$device->serial_number = $request->input('serial_number');
            if ($request->input('name')) {
                $device->name = $request->input('name');
            }
            $changedRetention = false;
            DB::beginTransaction();
            try {
                if ($request->input('log_retention') && $device_seat->log_retention != $request->input('log_retention')) {
                    if ($device->iccid) {
                        return response()->json(['message' => 'cannot change the seat of a cell device'], 404);
                    }
                    $free_seat = DB::table('device_seats')->where('account_id', $account->id)->where('occupied', false)->where('log_retention', $request->input('log_retention'))->first();
                    if (!$free_seat) {
                        return response()->json(['message' => 'no free seat'], 404);
                    }
                    $device->device_seat_id = $free_seat->id;
                    DB::table('device_seats')->where('id', $device_seat->id)->update(['occupied' => false]);
                    DB::table('device_seats')->where('id', $free_seat->id)->update(['occupied' => true]);
                    $changedRetention = true;
                }
                $device->save();
                DB::commit();
            } catch (\Illuminate\Database\QueryException $e) {
                DB::rollBack();
                return response()->json(['message' => $e], 404);
            }

            //notify the proxy that there was a change in retention if needed
            if ($changedRetention) {
                $this->notifyProxy($device, $request->input('log_retention'));
            }

            return response()->json(['message' => 'success']);
        } else {
            return response()->json(['message' => 'device not found'], 404);
        }
    }

    // ...

    // The CellDataProvider parameter is for dependency injection. That means when this function is called, the class
    // is instantiated and we get the instantiated object as a parameter.
    public function dataUsage($AccountId = 'self', Request $request, CellDataProvider $cellDataProvider, $id, $path = "")
    {
        $account = $this->getAccount($AccountId, $request);
        if ($account == null) {
            return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
        }

        $device = $account->devices()->where('device_id', $id)->firstOrFail();
        return $cellDataProvider->getDataUsage($device, $request, $path);
        // return $cellDataProvider->getDataUsageForUIDs(['65ce1f4b256a8d32ae0dadbb', '65ce1f4bb1aca10a1fe031a1'], $request); // for testing with UIDs
    }

    public function activateCell($AccountId = 'self', Request $request, $deviceId, CellDataProvider $cellDataProvider)
    {
        $account = $this->getAccount($AccountId, $request);
        if ($account == null) {
            return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
        }
        
        $device = $account->devices()->where('device_id', $deviceId)->firstOrFail();
        if (!empty($device->iccid)) {
            if ($device->activated == 0) {
                $sim_card = DB::table("SIM_cards")->where('serial_number', $device->serial_number)->where('account_id', $device->account_id)->select(['expire','data_plan','service_provider'])->first();
                $response = $cellDataProvider->activate($device, '', $sim_card->data_plan);

                // The following were made for testing purposes:
                // $response = $cellDataProvider->activate($device, '', '5MB');
                // $response = $cellDataProvider->suspend([$device, $device], '');
                // $response = $cellDataProvider->resume([$device, $device], '');
                // $response = $cellDataProvider->changeOffer([$device, $device], '', '300MB');
                // $response = $cellDataProvider->networkDetach($device);
                // return $response;

                $validResponse = false;
                if ($sim_card->service_provider == "telit") {
                    $validResponse = (isset($response['cmd']) && isset($response['cmd']['success']) && $response['cmd']['success'] === true);
                }
                else {
                    $validResponse = is_object($response) && (property_exists($response, 'operation'));
                }

                if ($validResponse) {
                    $device->activation_operation_id = isset($response->operation) ? $response->operation : $device->air_vantage_uid; // If no operation in response, it's Telit; set to conenction ID.
                    DB::beginTransaction();
                    try {
                        $device->save();
                        $nextYear = date('Y-m-d',strtotime(date("Y-m-d", time()) . " + 365 day"));
                        DB::table("SIM_cards")->where('serial_number', $device->serial_number)->where('account_id', $device->account_id)->update(['activation_date' => date("Y-m-d H:i:s"), 'expire' => $nextYear,  'cell_active' => true]);
                        // we use $sim_card for the values here because we want to save the expire BEFORE it was updated
                        /// $device_seat_history = $sim_card->device_seat_history()->create(['expire'=>$sim_card->expire,'do_not_renew'=>$sim_card->do_not_renew,'history_type'=>'SIM_cards']); // Commented out for now. Andrew is going to look into this.
                        // $sim_card is just set to the data plan, not an actual SimCard object.
                        // Also, we need to include a reference_id (for device_seat_history) or we will get an error!
                        // $device_seat_history->save(); // Commented out for now. Andrew is going to look into this.
                        DB::commit();
                    } catch (\Illuminate\Database\QueryException $e) {
                        DB::rollback();
                        return response()->json(['message' => $e], 422);
                    }
                    catch(Exception $e){
                        DB::rollback();
                        return response()->json(['message' => 'Failed to activate cell device'], 422);
                    }
                    return response()->json(['message' => 'success', 'act_op_id' => $device->activation_operation_id]);
                } else {
                    return response()->json(['message' => 'Failed to activate cell device'], 422);
                }
            }
            else{
                return response()->json(['message' => 'Cell device already activated'], 422);
            }
        }
        return response()->json(['message' => 'error']);
    }

    public function checkActiveStatus($AccountId = 'self', Request $request, $deviceId, CellDataProvider $cellDataProvider)
    {
        $account = $this->getAccount($AccountId, $request);
        if ($account == null) {
            return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
        }

        $device = $account->devices()->where('device_id', $deviceId)->firstOrFail();
        $lifeCycleState = $cellDataProvider->getLifeCycleState($device);
        if (($lifeCycleState == 'ACTIVE') || ($lifeCycleState == 'TEST_READY')) {
            $device->activated = 1;
            $device->activation_operation_id = ""; // clear the operation id for the activation since the sim is now active.
        } else {
            $device->activated = 0;
        }

        $device->save();

        return response()->json(['activated' => $device->activated, 'act_op_id' => $device->activation_operation_id]);
    }

    private function checkDeviceActivationOperation(Device $device, CellDataProvider $cellDataProvider)
    {
        $lifeCycleState = $cellDataProvider->getLifeCycleState($device);
        if (($lifeCycleState == 'ACTIVE') || ($lifeCycleState == 'TEST_READY'))
            return 1;
        else
            return 0;
    }

    // ...

    public function performCellAction(Request $request, CellDataProvider $cellDataProvider) {
        // Validate post body
        $request->validate([
            'devices' => 'required',
			'action' => 'required',
            'test' => 'boolean'
        ]);

        // Validate devices
        $good_devices = [];
        $not_found_devices = [];
        $not_cell_devices = [];

        foreach($request->devices as $device) {
                try {
                    if($device->iccid == null) {
                        array_push($not_cell_devices, $device);
                        continue;
                    }
                    array_push($good_devices, $device);
                }						
                catch(ModelNotFoundException $e) {
                    if(!isset($not_found_devices[$account_id])) {
                        $not_found_devices[$account_id] = [];
                    }													
                    array_push($not_found_devices[$account_id], $device_id);	
                }
                catch(Exception $e) {
                    return response(['message' => 'Unhandeled exception', 'error' => $e], 500);	
                }			
        }

        // Return devices not found
        if(count($not_found_devices) > 0) {
            return response(['message' => 'Devices not found', 'devices' => $not_found_devices], 404);			
        }

        // Return devices that aren't cell devices
        if(count($not_cell_devices) > 0) {
            $formatted_not_cell = [];
            foreach($not_cell_devices as $device) {
                if(!isset($formatted_not_cell[$device->account_id])) {
                    $formatted_not_cell[$device->account_id] = [];
                }	
                array_push($formatted_not_cell[$device->account_id], $device->device_id);
            }

            return response(['message' => 'Devices not cell devices', 'devices' => $formatted_not_cell], 400);
        }

        // If we are testing, we don't actually want to execute the CellDataProvider request
        if(!$request->test) {
            $response = [];

            if($request->action == 'resume') {
                // Perform resume request to CellDataProvider
                $response = $cellDataProvider->resume($good_devices);
            }
            else if($request->action == 'suspend') {
                $response = $cellDataProvider->suspend($good_devices);
            }
            else if($request->action == 'changeoffer5MB'){
                $response = $cellDataProvider->changeOffer($good_devices, '','5MB');
                foreach($good_devices as $device){
                    DB::table('SIM_cards')->where('air_vantage_uid', $device->air_vantage_uid)->update(['data_plan' =>'5MB' ]);
                }                
            }
            else if($request->action == 'changeoffer10MB'){
                $response = $cellDataProvider->changeOffer($good_devices, '','10MB');
                foreach($good_devices as $device){
                    DB::table('SIM_cards')->where('air_vantage_uid', $device->air_vantage_uid)->update(['data_plan' =>'10MB' ]);
                }
            }
            else if($request->action == 'changeoffer30MB'){
                $response = $cellDataProvider->changeOffer($good_devices, '','30MB');
                foreach($good_devices as $device){
                    DB::table('SIM_cards')->where('air_vantage_uid', $device->air_vantage_uid)->update(['data_plan' =>'30MB' ]);
                }
            }
            else if($request->action == 'changeoffer50MB'){
                $response = $cellDataProvider->changeOffer($good_devices, '','50MB');
                foreach($good_devices as $device){
                    DB::table('SIM_cards')->where('air_vantage_uid', $device->air_vantage_uid)->update(['data_plan' =>'50MB' ]);
                }
            }
            // Return 500 if CellDataProvider request fails
            if($cellDataProvider->getCurlResponseCode() != 200) {
                return response(['message' => 'CellDataProvider error', 'response' => $response, 'response_status_code' => $cellDataProvider->getCurlResponseCode()], 500);
            }
        }

        return response(['message' => "Successfully performed $request->action on devices"], 200);
    }

    // ...

}

