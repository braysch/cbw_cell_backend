<?php

namespace App\Http\Controllers\API\v1\sales;

use CBWCloud\Influx;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use CBWCloud\Models\Device;
use CBWCloud\Models\SimCard;
use CBWCloud\Models\WhiteLabel;
use CBWCloud\Models\User;
use CBWCloud\Models\Account as AccountModel;
use App\CommonClasses\RemoteDeviceAccess;
use App\CommonClasses\CellDataProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\CommonClasses\AccountAccess;
use Illuminate\Database\QueryException;
use App\CommonClasses\CellDataProviderFactory;
use Illuminate\Support\Facades\Mail;
use App\Notifications\DataUsageNotification;

class SIMCardController extends Controller
{
    use AccountAccess;
    
    // ...

		/**
		 * CellDataProvider suspend callback
		 *
		 * @param Illuminate\Http\Request $request
		 * @return Illuminate\Http\Response
		 */
		public function suspend(Request $request) {
			// Validate request is from CellDataProvider
			$rule_uid = '********';
			if(!isset($request->content['rule.uid'])) {
				return response(['message' => 'Bad request.'], 400);
			}
			else if($request->content['rule.uid'] !== $rule_uid) {
				return response(['message' => 'Unauthorized.'], 401);
			}	

			// Check if target.uid is set
            $uid = "";
			if(!isset($request->content['target.uid'])) {
                // Also check iccid (Telit cannot send uid in request body)
                if(!isset($request->content['target.iccid'])) {
                    Log::error('Request sent without target.uid to suspend');
				    return response(['message' => 'Bad request.'], 400);
                } else {
                    // get uid from iccid
                    $uid = SimCard::where('iccid', $request->content['target.iccid'])->value('air_vantage_uid');
                    if (!$uid) {
                        Log::error('Could not get uid from iccid to suspend');
				        return response(['message' => 'Bad request.'], 400);
                    }
                }
			} else {
                $uid = $request->content['target.uid'];
            }

			// Set suspended value
			DB::beginTransaction();
			try {
				$sim_card = DB::table('SIM_cards')->where('air_vantage_uid', $uid)->first();
				if($sim_card == null) {
					Log::error("Target $uid requested in suspend and not found");
					return response(['message' => 'Target not found'], 404);
				}
				
				DB::table('SIM_cards')->where('air_vantage_uid', $uid)->update(['suspended' => true]);
				DB::commit();
			}
			catch(QueryException $e) {
				DB::rollback();
				Log:error("Database error occurred in suspend: " . $e->getMessage());
				return response(['message' => 'Database error occurred', 'error' => $e->getMessage()], 500);
			}
			catch(Exception $e) {
				DB::rollback();
				Log::error("Unhandled execption in suspend: " . $e->getMessage());
				return response(['message' => 'Unexpected error occurred', 'error' => $e->getMessage()], 500);
			}

			// Return ok response
			return response(['message' => 'Request received'], 200);
		}

		/**
		 * CellDataProvider resume callback
		 *
		 * @param Illuminate\Http\Request $request
		 * @return Illuminate\Http\Response
		 */
		public function resume(Request $request) {
			// Validate request is from CellDataProvider
			$rule_uid  = '********';
			if(!isset($request->content['rule.uid'])) {
				return response(['message' => 'Bad request.'], 400);
			}
			else if($request->content['rule.uid'] !== $rule_uid) {
				return response(['message' => 'Unauthorized.'], 401);
			}

			// Check if target.uid is set
            $uid = "";
			if(!isset($request->content['target.uid'])) {
                // Also check iccid (Telit cannot send uid in request body)
                if(!isset($request->content['target.iccid'])) {
                    Log::error('Request sent without target.uid to resume');
				    return response(['message' => 'Bad request.'], 400);
                } else {
                    // get uid from iccid
                    $uid = SimCard::where('iccid', $request->content['target.iccid'])->value('air_vantage_uid');
                    if (!$uid) {
                        Log::error('Could not get uid from iccid to resume');
				        return response(['message' => 'Bad request.'], 400);
                    }
                }
			} else {
                $uid = $request->content['target.uid'];
            }

			// Set suspended value
			DB::beginTransaction();
			try {
                Log::channel('testing')->info("uid=$uid");
				$sim_card = DB::table('SIM_cards')->where('air_vantage_uid', $uid)->first();
				if($sim_card == null) {
					Log::error("Target $uid requested in resume and not found");
					return response(['message' => 'Target not found'], 404);
				}
				
				DB::table('SIM_cards')->where('air_vantage_uid', $uid)->update(['suspended' => false]);
				DB::commit();
			}
			catch(QueryException $e) {
				DB::rollback();
				Log:error("Database error occurred in resume: " . $e->getMessage());
				return response(['message' => 'Database error occurred', 'error' => $e->getMessage()], 500);
			}
			catch(Exception $e) {
				DB::rollback();
				Log::error("Unhandled execption in resume: " . $e->getMessage());
				return response(['message' => 'Unexpected error occurred', 'error' => $e->getMessage()], 500);
			}

			// Return ok response
			return response(['message' => 'Request received'], 200);
		}
        /**
         * CellDataProvider change offer callback
         *
         * @param Illuminate\Http\Request $request
         * @return Illuminate\Http\Response
         */
        public function changeOffer(Request $request, CellDataProvider $cellDataProvider) {
            // Validate request is from CellDataProvider
            $rule_uid_5mb  = '********';
            $rule_uid_10mb  = '********';
            $rule_uid_30mb  = '********';
            // $rule_uid_50mb = 'abc123';

            if(!isset($request->content['rule.uid'])) {
                    return response(['message' => 'Bad request.'], 400);
            }
            else if($request->content['rule.uid'] !== $rule_uid_5mb &&
                    $request->content['rule.uid'] !== $rule_uid_10mb &&
                    $request->content['rule.uid'] !== $rule_uid_30mb // &&
                    // $request->content['rule.uid'] !== $rule_uid_50mb
               ) {
                    return response(['message' => 'Unauthorized.'], 401);
            }

            // Check if target.uid is set
            if(!isset($request->content['target.uid'])) {
                    Log::error('Request sent without target.uid to changeOffer');
                    return response(['message' => 'Bad request.'], 400);
            }

            // check if the system.offerId is set
            if(!isset($request->content['attributes'][0]['value']['valueStr'])){
                    return response(['message' => 'Bad request.'], 400);
            }
            // this will default to the 10MB plan if the offierID is unknown
            $plan = $cellDataProvider->getPlanByOfferID($request->content['attributes'][0]['value']['valueStr']);
            Log::error('SIMcardController.changeOffer:'.$request->content['attributes'][0]['value']['valueStr']);
            // Set suspended value
            $air_vantage_uid = $request->content['target.uid'];
            DB::beginTransaction();
            try {
                    $sim_card = DB::table('SIM_cards')->where('air_vantage_uid', $air_vantage_uid)->first();
                    if($sim_card == null) {
                            Log::error("Target $air_vantage_uid requested in changeOffer and not found");
                            return response(['message' => 'Target not found'], 404);
                    }

                    DB::table('SIM_cards')->where('air_vantage_uid', $air_vantage_uid)->update(['data_plan' =>$plan ]);
                    DB::commit();
            }
            catch(QueryException $e) {
                    DB::rollback();
                    Log::error("Database error occurred in changeOffer: " . $e->getMessage());
                    return response(['message' => 'Database error occurred', 'error' => $e->getMessage()], 500);
            }
            catch(Exception $e) {
                    DB::rollback();
                    Log::error("Unhandled execption in changeOffer: " . $e->getMessage());
                    return response(['message' => 'Unexpected error occurred', 'error' => $e->getMessage()], 500);
            }
            //      Log::channel('testing')->info($request->collect());

            // Return ok response
            return response(['message' => 'Request received'], 200);

        }
        		/**
		 * CellDataProvider report usage callback
		 *
		 * @param Illuminate\Http\Request $request
		 * @return Illuminate\Http\Response
		 */
    
		public function reportUsage(Request $request) {//
            error_log("simcardcontroller.reportUsage called");
            $rule_uid_10MB  = '********';

            if(!isset($request->content['rule.uid'])) {
                return response(['message' => 'Bad request.'], 400);
            }
            else if($request->content['rule.uid'] != $rule_uid_10MB) {
                    return response(['message' => 'Unauthorized.'], 401);
            }

            // Check if target.uid is set
            if(!isset($request->content['target.uid'])) {
                    Log::error('Request sent without target.uid to airVantageReportUsage');
                    return response(['message' => 'Bad request.'], 400);
            }

            // check if the system.offerId is set
            if(!isset($request->content['attributes'][0]['value']['valueAgg'])){
                    return response(['message' => 'Bad request.'], 400);
            }
            $cellDataProvider = new CellDataProvider();
            // this will show the sum of the data for this time period
            Log::error('SIMcardController.reportUsage:'.$request->content['attributes'][0]['value']['valueAgg']);
            
            $air_vantage_uid = $request->content['target.uid'];
            



/*


(
    [rule.uid] => 1b62ced7a7404d83ac2cb326c6eac761
    [target.uid] => fb264fe97c3c4de8aedd1f8fcd58edec
    [state] => 1
    [attributes] => Array
        (
            [0] => Array
                (
                    [id] => Array
                        (
                            [name] => AGG.DATA_BYTES_RECEIVED/D
                        )

                    [value] => Array
                        (
                            [valueAgg] => Array
                                (
                                    [sum] => 516827
                                )

                        )

                    [refTime] => 1675270638000
                )

        )

)








                   report usage to a file?


                */
                Log::channel('info')->info('DataUsage '. print_r($request->content,true)); 
				
			

			// Return ok response
			return response(['message' => 'Request received'], 200);
		}
        
        public function changePrimaryIccid(Request $request) {
            $requestData = $request->json()->all();
            
            if (!isset($requestData['serialNumber'])) { return "Serial number not found in request body"; }
            $serialNumber = $requestData['serialNumber'];
            
            if (!isset($requestData['eid'])) { return "EID not found in request body"; }
            $eid = $requestData['eid'];

            if (!isset($requestData['iccid'])) { return "ICCID not found in request body"; }
            $iccid = $requestData['iccid'];
            
            $simCardProfile = '';
            if (isset($requestData['simCardProfile'])) { $simCardProfile = $requestData['simCardProfile']; } 

            $sim = SimCard::where('serial_number', $serialNumber)->first();

            if (!$sim) {
                Log::channel('testing')->info('SimCard not found for serial number: ' . $serialNumber);
                return; // Or handle the case where the SimCard is not found
            }

            Log::channel('testing')->info($sim->iccid);

            $cellDataProvider = CellDataProviderFactory::create($sim->service_provider);

            $response = $cellDataProvider->setActiveProfile($eid, $iccid);
            if ($response !== true) {
                Log::channel('testing')->info('FAILURE');
                return $response;
            }

            Log::channel('testing')->info('SUCCESS!');
            $sim->alt_iccid = $sim->iccid === $iccid ? null : $iccid;
            $sim->sim_card_profile = $simCardProfile;
            $sim->save();

            DB::commit();
            Log::channel('testing')->info("Saved SIM Card Info");
        }

         public function sendDataUsageNotification(Request $request) {
            $requestData = $request->json()->all();

            // When accessing this endpoint from Telit, only the iccid may be sent
            // So we need to check for an iccid, and find the corresponding uid
            $uid = null;
            $iccid = null;

            if (isset($requestData['content']['target.uid']))
            {
                $uid = $requestData['content']['target.uid'];
                $iccid = SimCard::where('air_vantage_uid', $uid)->value('iccid');
                if (!$iccid) { return "SIM with uid '" . $uid . "' not found in database"; }
            }
            else if (isset($requestData['content']['target.iccid']))
            {
                $iccid = $requestData['content']['target.iccid'];
                $uid = SimCard::where('iccid', $iccid)->value('air_vantage_uid');
                if (!$uid) { return "SIM with iccid '" . $iccid . "' not found in database"; }
            }
            else
            {
                return "Could not find uid (" . $uid . ") or iccid (" . $iccid . ") in request...";
            }
            $device = Device::where('iccid', $iccid)->firstOrFail();
            $ancestors = AccountModel::ancestorsAndSelf($device->account_id);
            $topParent = collect($ancestors)->first(); // get the top parent (the ancestor beneath the 2123 account)
            $isCBWChild = $topParent->parent_id == env('XRDI_PARENT_ACCOUNT_ID');
            $user = User::where('account_id', $topParent->id)->where('account_admin', true);
            $email = $user->value('email');
            $name = $user->value('first_name');
            $sim = SimCard::where('air_vantage_uid', $uid);
            $plan = $sim->value('data_plan');
            $cellDataProvider = CellDataProviderFactory::create($sim->value('service_provider'));
            $domain = WhiteLabel::where("attribute_name","=","domain")->where("account_id","=",$topParent->id)->value('attribute_value');
            $subdomain = WhiteLabel::where("attribute_name","=","subdomain")->where("account_id","=",$topParent->id)->value('attribute_value');

            if ($sim->value('alt_iccid') != null) { $uid = $cellDataProvider->getUIDfromICCID($sim->value('alt_iccid')); }

            $dataResponse = $cellDataProvider->getDataUsageForUIDs([$uid], $request);
            if ($sim->value('service_provider') != 'telit') { $dataResponse = json_decode($dataResponse, true); } // telit already decoded
            $bytes = $dataResponse[$uid]['DATA_ROUNDED_BYTES_TOTAL'][0]['v'];
            foreach (['simusage@controlbyweb.com', $email] as $recipient) {
            // foreach ([$email] as $recipient) {
                Mail::to($recipient)->send(new DataUsageNotification($plan, $device, $bytes, $isCBWChild, $name, $domain, $subdomain));
                Log::channel('testing')->info("Data usage notification sent successfully to " . $recipient);
            }
            return ("Data usage notification sent successfully");
        }
        
        public function networkDetach($deviceId, Request $request) {
            $account = $this->getAccount('self', $request);
            if ($account == null) {
                return response()->json(['message' => 'Access to this resource is forbidden for this user.'], 403);
            }
           //Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach request");
                        
            $device = Device::where('device_id','=',$deviceId)->firstOrFail();
            //Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach device found:".$device->device_id. " iccid:'".$device->iccid. "' air_vantage_uid:'".$device->air_vantage_uid."'");
            if(empty($device->air_vantage_uid) || empty($device->iccid))  // make sure it's a cell device
            {
                return response(['message' => 'Bad request.'], 400);
            }
           // Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach checking account descendant");
            /*
               check to make sure this device's account_id is in the descendent list of the authenticated user
            */
            $descendants = Account::descendantsAndSelf($device->account_id);
            $found = false;
            foreach ($descendants as $a) {
                if($a->id == $account->id){
                    //Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach found valid account");
                    $found = true;
                    break;
                }
            }
            if(!$found){
                return response(['message' => 'Unauthorized.'], 401);
            }
           // return response(['message' => 'testing testing'], 400);
            $cellDataProvider = new CellDataProvider();
            $responseCode = $cellDataProvider->networkDetach($device);  // this just returns a response code
            if($responseCode === 200){
                Log::channel('testing')->info("SIMCardController.networkDetach for device '".$device->device_id."' air_vantage_id:'".$device->air_vantage_id."'");
                return response(['message' => 'Request received'], 200);
            }
            elseif($responseCode === 429){
                Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach Too many requests for '".$device->device_id."' air_vantage_id:'".$device->air_vantage_id."'");
                return response()->json(['message' => 'Too many requests'], 429);
            }
            else{
                Log::channel('testing')->info("SIMCardController.airVAntageNetworkDetach failed for '".$device->device_id."' air_vantage_id:'".$device->air_vantage_uid."'");
                return response()->json(['message' => 'Failed to detach device from network'], 422);
            }
        }
        
}
