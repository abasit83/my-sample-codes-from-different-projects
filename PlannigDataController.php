<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetsHistory;
use App\Models\CompositeSites;
use App\Models\PlanData;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PlannigDataController extends Controller
{
    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function insertPlanningData(Request $request)
    {
        increaseLimit();
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'items' => 'present|array',
        ]);
        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid Data was found.'
            ), 406);
        } else {
            $planningDataArray = $request->all();
            if (isset($planningDataArray['items']) && !empty($planningDataArray['items'])) {
                $col_lookup = array(
                    'transactionId' => "Transaction Id",
                    'siteId' => "Site Id",
                    'serialNumber' => "Serial Number",
                );
                $validations = array(
                    'transactionId' => "Transaction Id",
                    'siteId' => "Site Id",
                    'serialNumber' => "Serial Number",
                );

                $log = array();
                $lineError = 0;
                $array_to_insert = [];
                $sno_site_id_lookup = $composite_site_id_lookup = $warehouse_lookup = [];

                $serial_numbers = array_column($planningDataArray['items'], 'serialNumber');
                $assets = Asset::select('site_id', 'manufacturer_qr_code')->whereIn('manufacturer_qr_code', $serial_numbers)->get();
                if(count($assets)) {
                    $assets = $assets->toArray();
                    $sno_site_id_lookup = array_column($assets, 'site_id', 'manufacturer_qr_code');
                    
                    $site_ids = array_unique(array_values($sno_site_id_lookup));
                    $composite_site_data = CompositeSites::select('id', 'site_code', 'site_type_id', 'composite_site_id')->whereIn('composite_site_id', $site_ids)->get();
                    
                    if(count($composite_site_data)) {
                        $composite_site_data = $composite_site_data->toArray();
                        $composite_site_id_lookup = array_column($composite_site_data, 'site_code', 'composite_site_id');

                        $wh_ids = $wh_array = [];
                        foreach($composite_site_data as $key => $val) {
                            if($val['site_type_id'] == 9) {
                                unset($composite_site_id_lookup[$val['composite_site_id']]);        // REMOVING WAREHOUSE 
                                $wh_ids[] = $val['id'];
                                $wh_array[$val['id']] = $val['composite_site_id'];
                            }
                        }

                        if(count($wh_ids)) {
                            $warehouses = Warehouse::where('creation_source', 1)->whereIn('id', $wh_ids)->get();
                            if(count($warehouses)) {
                                foreach($warehouses as $wh) {
                                    if(isset($wh_array[$wh->id])) {
                                        // CREATING LOOKUP array['composite_site_id'=>'site_code] e.g. array[109 => "INX_ISB"]
                                        $warehouse_lookup[$wh_array[$wh->id]] = ($wh->short_name != NULL || trim($wh->short_name) != '') ? $wh->short_name : null;
                                    }
                                }
                            }
                        }
                    }
                }    
                
                $validate_duplicates = $duplicates_array['items'] = [];
                foreach ($planningDataArray['items'] as $row) {
                    $class = new \stdCLass();
                    foreach ($row as $key => $value) {
                        $fieldTitle = $col_lookup[$key] ?? '';
                        $field = $key ?? '';
                        if (!isset($row[$key])) {
                            $row[$key] = null;
                        } else {
                            $row[$key] = trim($row[$key]);
                        }
                        if (isset($validations[$field])) {
                            if (isset($row[$key])) {
                                $class->{$field} = $row[$key];
                            } else {
                                $log['error'][] = array(
                                    'transactionId' => $row['transactionId'],
                                    'siteId' => $row['siteId'],
                                    'serialNumber' => $row['serialNumber'],
                                    'errorReason' => $fieldTitle . " not found."
                                );
                                $lineError++;
                            }
                        } else {
                            $log['error'][] = array(
                                'transactionId' => $row['transactionId'],
                                'siteId' => $row['siteId'],
                                'serialNumber' => $row['serialNumber'],
                                'errorReason' => $fieldTitle . " not found."
                            );
                            $lineError++;
                        }
                    } 

                    if ($lineError == 0) {
                        $site_code = '';
                        if(isset($sno_site_id_lookup[$class->serialNumber]) && isset( $warehouse_lookup[$sno_site_id_lookup[$class->serialNumber]] ) ) {
                            $site_code = $warehouse_lookup[$sno_site_id_lookup[$class->serialNumber]];
                        }
                        elseif(isset($sno_site_id_lookup[$class->serialNumber]) && isset( $composite_site_id_lookup[$sno_site_id_lookup[$class->serialNumber]] ) ) {
                            $site_code = $composite_site_id_lookup[$sno_site_id_lookup[$class->serialNumber]];
                        }
                        
                        $insert_key = $class->transactionId.'__'.$class->siteId.'__'.$class->serialNumber;        // AVOID DUPLICATIONS
                        if(isset($validate_duplicates[$insert_key])) {                    
                            $validate_duplicates[$insert_key] = $validate_duplicates[$insert_key]+1;
                        }
                        else {
                            $validate_duplicates[$insert_key] = 1;
                        }

                        $array_to_insert[$insert_key] = array(
                            'transaction_id' => $class->transactionId,
                            'site_id' => $class->siteId,
                            'serial_number' => $class->serialNumber,
                            'wrong_site_id_flag' => ($class->siteId != $site_code && trim($site_code) != '') ? 1 : '',
                            'actual_site_id' => $site_code,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        );
                    }
                }

                if(isset($log['error']) && count($log['error'])) {
                    return response()->json(array(
                        'success' => false,
                        'message' => 'Invalid Data Received.',
                        'errorDetails' => $log['error']
                    ), 400);
                }

                foreach($validate_duplicates as $key => $val) {
                    if($val > 1) {
                        $arr = explode('__', $key);
                        $insert = [
                            'transactionId' => $arr[0],
                            'siteId' => $arr[1],
                            'serialNumber' => $arr[2],
                        ];
                        for($i=0; $i<$val; $i++) {
                            $duplicates_array['items'][] = $insert;
                        }
                    }
                }

                if(count($duplicates_array['items']) > 0) {
                    return response()->json(array(
                        'items' => $duplicates_array['items'],
                        'success' => false,
                        'message' => 'Duplicate data found.',
                    ), 200);
                }
                
                if(count($array_to_insert) && count($duplicates_array['items']) == 0) {
                    $insert_data = collect($array_to_insert);
                    $chunks = $insert_data->chunk(5000);
                    foreach ($chunks as $chunk)
                    {
                        $rec_inserted = DB::table('planning_data')->upsert(
                            $chunk->toArray(), 
                            ['transaction_id', 'site_id', 'serial_number'],
                            ['transaction_id', 'site_id', 'serial_number'],
                        );
                    }
                    $log['success'][] = 'Record(s) added successfully';
                }

                if (isset($log['success'])) {
                    return response()->json(array(
                        'success' => true,
                        'message' => 'Record(s) added successfully',
                    ), 200);
                } else {
                    return response()->json(array(
                        'success' => false,
                        'message' => 'Something went wrong.'
                    ), 400);
                }
            } 
        } 
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReconcilliationData(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10000;
        $start_date = isset( $request->start_date ) ? $request->start_date : '';
        $end_date = isset( $request->end_date ) ? $request->end_date : '';
        $lastsyncdatetime = isset( $request->lastSyncDateTime ) ? $request->lastSyncDateTime : '';
        $serial_search = isset( $request->serial_search ) ? $request->serial_search : [];

        $PlanDataData = PlanData::latest()->select('id', 'transaction_id', 'actual_site_id AS site_id', 'serial_number', 'read_at', 'created_at', 'updated_at');
        $PlanDataData->where('wrong_site_id_flag', '=', true);
        $PlanDataData->where('actual_site_id', '!=', '');
        $PlanDataData->where('actual_site_id', '!=', null);
        if (isset($lastsyncdatetime) && !empty($lastsyncdatetime)) {
            $PlanDataData->where('updated_at', '>=', $lastsyncdatetime);
            $PlanDataData->where('read_at', '=', NULL);
        } 
        elseif($start_date && $end_date) {
            $PlanDataData->where('updated_at', '>=', $start_date)->where('updated_at', '<=', $end_date);
        }
        elseif(is_array($serial_search) && count($serial_search)) {
            $PlanDataData->whereIn('serial_number', $serial_search);
        }
        else {
            $PlanDataData->where('read_at', '=', NULL);
        }
        if (isset($offset) && isset($limit)) {
            $PlanDataData->offset($offset)->limit($limit);
        }
        $returnDataArray = $PlanDataData->get();
        
        $totalCount = count( $returnDataArray ); // We need to change the limit and offset interaction. This will be changed at that time.
        $ids = Arr::pluck($returnDataArray, 'id');
        $responseData = array();
        if ( !empty($returnDataArray) ) {
            $returnArray = array();
            foreach ($returnDataArray as $single) {
                $returnArray[] = array(
                    'transaction_id' => $single->transaction_id,
                    'site_id' => $single->site_id,
                    'serial_number' => $single->serial_number,
                );
            }
            $returnDataArray = $returnArray;
            $responseData = array(
                'success' => true,
                'Total Record' => $totalCount,
                'items' => $returnDataArray
            );

            DB::table('planning_data')
                ->whereIn('id', $ids)
                ->update(['read_at' => date('Y-m-d H:i:s')]);
        } else {
            $responseData = array(
                'success' => true,
                'Total Record' => 0,
                'items' => array()
            );
        }
        return response()->json($responseData, 200);
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssetTag(Request $request)
    {
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'items' => 'present|array',
        ]);
        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid Data received'
            ), 406);
        } else {
            $QRDataArray = $request->all();
            $responseData = array();
            if (isset($QRDataArray['items']) && !empty($QRDataArray['items'])) {
                $lastSyncDateTime = (isset($QRDataArray['lastSyncDateTime'])) ? date('Y-m-d H:i:s', strtotime(($QRDataArray['lastSyncDateTime']))) : null;
                $items = $QRDataArray['items'];
                $inputArray = array_map("unserialize", array_unique(array_map("serialize", $items)));
                $responseData['status'] = true;
                foreach ($inputArray as $single) {
                    $temp = array();
                    if (!empty($single['serialNumber'])) {
                        $assetRecordQuery = Asset::select('asset_qr_code')
                            ->where('manufacturer_qr_code', '=', $single['serialNumber']);
                        if ($lastSyncDateTime != null) {
                            $assetRecordQuery->where('created_at', '>', $lastSyncDateTime);
                        }
                        $assetRecord = $assetRecordQuery->first();
                        if ($assetRecord) {
                            $temp = array(
                                'assetNumber' => $single['assetNumber'],
                                'serialNumber' => $single['serialNumber'],
                                'assetTag' => $assetRecord->asset_qr_code
                            );
                        } else {
                            $temp = array(
                                'assetNumber' => $single['assetNumber'],
                                'serialNumber' => $single['serialNumber'],
                                'assetTag' => null
                            );
                        }
                    } else {
                        $temp = array(
                            'assetNumber' => $single['assetNumber'],
                            'serialNumber' => $single['serialNumber'],
                            'assetTag' => null
                        );
                    }
                    $responseData['items'][] = $temp;
                }
            }
            return response()->json($responseData, 200);
        }
    }

    /**
     * This function is used to get Detail of Asset Event i,e Added, Moved and Removal
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssetMovementDetail(Request $request)
    {
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date_format:Y-m-d H:i:s',
            'to_date' => 'required|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid Data received'
            ), 406);
        }

        $from_date = $request->input('from_date');
        $to_date = $request->input('to_date');

        $data = AssetsHistory::latest()
            ->with('asset')
            ->whereBetween('created_at', [$from_date, $to_date])
            ->whereIn('history_type', ['Asset moved', 'Asset Added', 'Asset Removed'])
            ->orderBy('created_at', 'asc')
            ->get();

        $responseData = array();
        $generic_site_id_lookup = [];
        if(count($data)) {
            $data_array = $data->toArray();
            
            $from_site_ids = array_column($data_array, 'from_site_id');
            $to_site_ids = array_column($data_array, 'to_site_id');
            $all_site_ids = array_unique(array_merge($from_site_ids, $to_site_ids), SORT_REGULAR);

            $generic_site_id_lookup = CompositeSites::get_generic_site_id_lookup($all_site_ids);

            $returnArray = $timestamp_array = [];
            $serial_numbers = [];
            foreach ($data as $singleRow) {
                if ($singleRow->asset != null) {
                    if ($singleRow->asset->manufacturer_qr_code != null || $singleRow->asset->manufacturer_qr_code != '') {
                        $serial_numbers[$singleRow->asset->manufacturer_qr_code] = TRUE;
                        $temp = array();

                        $_from_site_id = $singleRow->from_site_id;
                        $_to_site_id = $singleRow->to_site_id;

                        if(!isset($generic_site_id_lookup[$_from_site_id])) continue;
                        
                        if($generic_site_id_lookup[$_from_site_id]['site_type'] == 9) continue;

                        if(trim($_to_site_id) != '' && isset($generic_site_id_lookup[$_to_site_id]) && $generic_site_id_lookup[$_to_site_id]['site_type'] == 9) continue;

                        $temp['sourceLocation'] = $generic_site_id_lookup[$_from_site_id]['generic_site_id'];           //str_replace(' (Generic Site ID)', '', $singleRow->from);
                        if ($singleRow->history_type == 'Asset Added') {
                            $temp['sourceLocation'] = null;
                        }

                        $temp['destinationLocation'] = (trim($_to_site_id) != '' && isset($generic_site_id_lookup[$_to_site_id])) ? $generic_site_id_lookup[$_to_site_id]['generic_site_id'] : '';        //str_replace(' (Generic Site ID)', '', $singleRow->to);
                        if ($singleRow->history_type == 'Asset Removed') {
                            $temp['destinationLocation'] = null;
                        }

                        $temp['serialNumber'] = $singleRow->asset->manufacturer_qr_code;
                        $temp['transactionDate'] = date('Y-m-d H:i:s', strtotime($singleRow->created_at));

                        if(!isset($timestamp_array[$temp['serialNumber']])){
                            $timestamp_array[$temp['serialNumber']] = [];
                        }
                       
                        if(!isset($location[$temp['serialNumber']])){
                            $location[$temp['serialNumber']] = [];
                        }
                        
                        if( isset($returnArray[ $temp['serialNumber'] ]) ) {
                            
                            if( isset($timestamp_array[$temp['serialNumber']]['add_ts']) && $temp['destinationLocation'] != null && $timestamp_array[$temp['serialNumber']]['add_ts'] < $temp['transactionDate'] ) {
                                $timestamp_array[$temp['serialNumber']]['add_ts'] = $temp['transactionDate'];
                                $returnArray[$temp['serialNumber']]['destinationLocation'] = $temp['destinationLocation'];
                                $returnArray[$temp['serialNumber']]['transactionDate'] = $temp['transactionDate'];
                            }
                            
                            if( isset($timestamp_array[$temp['serialNumber']]['removal_ts']) && $temp['sourceLocation'] != null && $timestamp_array[$temp['serialNumber']]['removal_ts'] > $temp['transactionDate'] ) {
                                $timestamp_array[$temp['serialNumber']]['removal_ts'] = $temp['transactionDate'];
                                $returnArray[$temp['serialNumber']]['sourceLocation'] = $temp['sourceLocation'];
                            }
                        } else {
                            $returnArray[$temp['serialNumber']] = $temp;
                            $timestamp_array[$temp['serialNumber']]['add_ts'] = $temp['transactionDate'];
                            $timestamp_array[$temp['serialNumber']]['removal_ts'] = $temp['transactionDate'];
                        }
                    }
                }
            }

            if($_count = count($returnArray)) {
                $final_array = [];
                $duplicates = DB::table('assets')
                            ->select('manufacturer_qr_code')
                            ->whereIn('manufacturer_qr_code', array_keys($serial_numbers))
                            ->groupBy(['manufacturer_qr_code'])
                            ->havingRaw('COUNT(manufacturer_qr_code) > 1')
                            ->pluck('manufacturer_qr_code');
                if($_count = count($duplicates)) {
                    $duplicates = array_unique($duplicates->toArray());
                }
                
                foreach($returnArray as $key => $val) {                    
                    if($val['sourceLocation'] == $val['destinationLocation']) {
                        unset($returnArray[$key]);
                        continue;
                    }
                    
                    if($_count > 0 && in_array($val['serialNumber'], $duplicates)) {
                        unset($returnArray[$key]);
                        continue;
                    }

                    $final_array[] = $val;
                }

                $responseData = array(
                    'success' => true,
                    'message' => 'Data Found',
                    'Total Record' => count($final_array),
                    'items' => $final_array
                );
            }
            else {
                $responseData = array(
                    'success' => false,
                    'message' => 'Data Not Found',
                    'Total Record' => 0,
                    'items' => ''
                );
            }
        }
        else {
            $responseData = array(
                'success' => false,
                'message' => 'Data Not Found',
                'Total Record' => 0,
                'items' => ''
            );
        }
        
        return response()->json($responseData, 200);
    }

    /**
     * Expose Site to Warehouse Movement data to ESS
     * This function is used to get Detail of Site to Warehouse Movement 
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssetReturnDetails(Request $request)
    {
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date_format:Y-m-d H:i:s',
            'to_date' => 'required|date_format:Y-m-d H:i:s',
        ]);
        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid request.'
            ), 406);
        }
    
        $from_date = $request->input('from_date');
        $to_date = $request->input('to_date');
        $data = AssetsHistory::latest()
            ->with('asset', 'asset.planned_asset_site')
            ->where('location_type', 'warehouse')
            ->whereBetween('created_at', [$from_date, $to_date])
            ->whereIn('history_type', ['Asset moved', 'Asset Removed'])
            ->get();

        $generic_site_id_lookup = [];
        if(count($data)) {
            $data_array = $data->toArray();

            $from_site_ids = array_column($data_array, 'from_site_id');
            $to_site_ids = array_column($data_array, 'to_site_id');
            $all_site_ids = array_unique(array_merge($from_site_ids, $to_site_ids), SORT_REGULAR);

            $generic_site_id_lookup = CompositeSites::get_generic_site_id_lookup($all_site_ids);
        
            $warehouses = Warehouse::select('id', 'name', 'short_name', 'creation_source', 'warehouse_type')->get()->toArray();
            $wh_type_lookup = array_column($warehouses, 'warehouse_type', 'name');
            $wh_name_shortname_lookup = array_column($warehouses, 'short_name', 'name');
            $wh_creation_source_lookup = array_column($warehouses, 'creation_source', 'name');
    
            $returnArray = array();
            foreach ($data as $singleRow) {
                if ($singleRow->asset != null) {
                    if (($singleRow->asset->manufacturer_qr_code != null || $singleRow->asset->manufacturer_qr_code != '') && $singleRow->from != '' ) {
                        $temp = array();
    
                        $_from_site_id = $singleRow->from_site_id;
                        $_to_site_id = $singleRow->to_site_id;

                        if(!isset($generic_site_id_lookup[$_from_site_id])) continue;
                        
                        if($singleRow->history_type == 'Asset moved' && trim($_to_site_id) != '' && isset($generic_site_id_lookup[$_to_site_id])) {
                            $temp['destinationLocation'] = $generic_site_id_lookup[$_to_site_id]['generic_site_id'];
                        }
                        elseif($singleRow->history_type == 'Asset Removed' && isset($singleRow->asset->planned_asset_site)) {
                            $temp['destinationLocation'] = $singleRow->asset->planned_asset_site->site_code;
                        }
                        else {
                            continue;
                        }
    
                        $temp['sourceLocation'] = $generic_site_id_lookup[$_from_site_id]['generic_site_id'];;
                        $temp['serialNumber'] = $singleRow->asset->manufacturer_qr_code;
                        $temp['eamPlanId'] = date('YmdHis', strtotime($singleRow->asset->created_at));
    
                        $wh_type = isset($wh_type_lookup[$temp['destinationLocation']]) ? trim($wh_type_lookup[$temp['destinationLocation']]) : '';
                        $wh_creation_source = isset($wh_creation_source_lookup[$temp['destinationLocation']]) ? trim($wh_creation_source_lookup[$temp['destinationLocation']]) : '';
                        
                        if(isset($wh_name_shortname_lookup[$temp['destinationLocation']]) && trim($wh_name_shortname_lookup[$temp['destinationLocation']]) != '') {
                            $temp['destinationLocation'] = $wh_name_shortname_lookup[$temp['destinationLocation']];
                        }
                        else {
                            continue;
                        }
    
                        $_wh_type_array = ($wh_type != '') ? json_decode($wh_type, true) : [];
                        if(!in_array('SPMS WH', $_wh_type_array) && $wh_creation_source == 1) {
                            array_push($returnArray, $temp);
                        }   
                    }
                }
            }
    
            if($_count = count($returnArray)) {
                $responseData = array(
                    'success' => true,
                    'message' => 'Data Found',
                    'Total Record' => $_count,
                    'items' => $returnArray
                );
            }
            else {
                $responseData = array(
                    'success' => false,
                    'message' => 'Data Not Found',
                    'Total Record' => 0,
                    'items' => ''
                );
            }
        }
        else {
            $responseData = array(
                'success' => false,
                'message' => 'Data Not Found',
                'Total Record' => 0,
                'items' => ''
            );
        }
        
        return response()->json($responseData, 200);
    }

    /**
     * API to send QR Code changes to ESS
     * History Type: QR Code Changed
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendQrCodeChanges(Request $request)
    {
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date_format:Y-m-d H:i:s',
            'to_date' => 'required|date_format:Y-m-d H:i:s',
        ]);
        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid data received'
            ), 406);
        }

        $from_date = $request->input('from_date');
        $to_date = $request->input('to_date');
        $data = AssetsHistory::with('asset')
            ->where('history_type', 'QR Code Changed')
            ->whereBetween('created_at', [$from_date, $to_date])
            ->orderBy('id', 'desc')
            ->get()
            ->unique('assets_id');

        $responseData = array(
            'success' => true,
            'message' => 'Data Not Found',
            'Total Record' => 0,
            'items' => ''
        );

        if ($data->isNotEmpty()) {
            $returnArray = array();
            foreach ($data as $singleRow) {
                if ($singleRow->asset != null) {
                    if ($singleRow->asset->manufacturer_qr_code != null || $singleRow->asset->manufacturer_qr_code != '') {
                        $temp = array();
                        
                        $temp['qrCode'] = $singleRow->to;                        
                        $temp['serialNumber'] = $singleRow->asset->manufacturer_qr_code;

                        array_push($returnArray, $temp);
                    }
                }
            }// endforeach

            if(count($returnArray) > 0) {
                $responseData = array(
                    'success' => true,
                    'message' => 'Data Found',
                    'Total Record' => count($returnArray),
                    'items' => $returnArray
                );
            }
        }
        
        return response()->json($responseData, 200);
    }
    
    /**
     * ESS integration INT 704 : SPMS asset replacement
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSpmsData(Request $request)
    {
        // Retrieve the validated input data...
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date_format:Y-m-d H:i:s',
            'to_date' => 'required|date_format:Y-m-d H:i:s',
        ]);
        if ($validator->fails()) {
            return response()->json(array(
                'success' => false,
                'message' => 'Invalid data received',
                'Total Record' => 0,
                'items' => []
            ), 406);
        }

        $from_date = $request->input('from_date');
        $to_date = $request->input('to_date');

        $data = AssetsHistory::with(['asset', 'new_asset_replacing'])
                ->whereHas('asset', function($query) {
                    $query->where('replacement_asset_id', '!=', 0);
                })
                ->where('replacement_asset_id', '!=', 0)
                ->where('replaced_asset_id', '!=', 0)
                ->where('field', 'Replaced Asset')
                ->where('remarks', 'SPMS')
                ->whereBetween('created_at', [$from_date, $to_date])
                ->orderBy('id', 'desc')
                ->get(); //->toArray();

        $final_array = [];
        $responseData = array(
            'success' => false,
            'message' => 'Data Not Found',
            'Total Record' => 0,
            'items' => $final_array
        );

        if($data->isNotEmpty()) {
            foreach($data as $d) {
                if(isset($d->new_asset_replacing) && ($d->new_asset_replacing->manufacturer_qr_code != '' || $d->new_asset_replacing->manufacturer_qr_code != null)) {
                    $temp_array = [];
                    $temp_array['OldSerialNumber'] = (isset($d->asset) && $d->asset->manufacturer_qr_code != '') ? $d->asset->manufacturer_qr_code : '';
                    $temp_array['NewSerialNumber'] = $d->new_asset_replacing->manufacturer_qr_code;
                    $temp_array['NewQRCode'] = $d->new_asset_replacing->asset_qr_code;
    
                    $final_array[] = $temp_array;
                }
            }
            
            if(($total_record = count($final_array)) > 0) {
                $responseData = array(
                    'success' => true,
                    'message' => 'Data Found',
                    'Total Record' => $total_record,
                    'items' => $final_array
                );
            }
        }

        return response()->json($responseData, 200);
    }
}