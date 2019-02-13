<?php

namespace App\Http\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Response;

trait GazingleApi {

    use GazingleMutation;

    public $time;
    public $request;
    public $request_names = [];
    public $search_params = [];
    public $maxResults = 200;


    /**
     * Determine if request contains a filters
     * @param Model $model
     * @return bool
     */
    protected function _isSearchRequest(Model $model){
        $isSearchRequest = false;
        foreach($model->searchable as $param){
            if (in_array($param, $this->request_names)) {
                $isSearchRequest = true;
            }
            if (in_array($param.'_like', $this->request_names)) {
                $isSearchRequest = true;
            }
        }
        if(!empty($this->request['meta']))
            $isSearchRequest = true;
        return $isSearchRequest;
    }

    /**
     * Search in Model by Model Searchable
     * @param Builder $items
     * @param Model $model
     * @return Builder
     */
    protected function _searchInModel(Model $model, Builder $items){
        foreach($model->searchable as $param){
            if($param != 'id'){
                if(isset($this->request[$param]))
                    $items = $items->where($param, $this->request[$param]);
                if(isset($this->request[$param.'_like']))
                    $items = $items->where($param, 'like', '%'.$this->request[$param.'_like'].'%');
            }else{
                if(isset($this->request['id'])){
                    $ids = explode(',', $this->request['id']);
                    $items = $items->whereIn('id', $ids);
                }
            }
        }

        return $items;
    }

    /**
     * Send "Success" response
     * @return Response
     */
    public function success($data, $message = 'OK'){
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'time' => $this->time
        ]);
    }

    /**
     * Send "Success" response
     * @return Response
     */
    public function paginatedSuccess($data, $message = 'OK'){
        $result = [
            'success' => true,
            'data' => (!is_array($data)) ? $data->toArray()['data'] : $data['data'],
            'message' => $message,
            'time' => $this->time
        ];
        foreach(array_except($data->toArray(), ['data']) as $key => $value){
            $result[$key] = $value;
        }
        return response()->json($result);
    }

    /**
     * Send "not Found" response
     * @return Response
     */
    public function notFound(){
        return response()->json([
            'success' => false,
            'message' => 'Not found',
            'time' => $this->time
        ], 404);
    }

    /**
     * Send "Wrong Data" response
     * @param string $message
     * @return Response
     */
    public function wrongData($message){
        return response()->json([
            'success' => false,
            'message' => $message,
            'time' => $this->time
        ], 412);
    }

    /**
     * Send "Server Error" response
     * @param string $message
     * @return Response
     */
    public function serverError($message){
        return response()->json([
            'success' => false,
            'message' => $message,
            'time' => $this->time
        ], 500);
    }

    /*
     * Parse and returns Api request depending on options provided to request
     * @param collection $items
     * @param Request $request
     * @return Response
     */
    public function returnParsed($items, $request){
        try{
//            if($request->has('columns')){
//                $columns = explode(",",str_replace(' ', '', $request->get('columns')));
//                $items = $items->select($columns);
//            }
            if($request->has('with_trashed')){
                $items = $items->withTrashed();
            }
            if($request->has('only_trashed')){
                $items = $items->onlyTrashed();
            }
            if ($request->has('just_count')){
                $count = $items->count();
                return $this->success(['total' => $count]);
            }
            if($request->has('limit')){
                $items = $items->take($request->limit);
            }else{
                $items = $items->take($this->maxResults);
            }
            if($request->has('exclude')){
                $items = $items->whereNotIn('id', explode(',', $request->exclude));
            }
            if($request->has('paginate')){
                $response = $items->paginate($request->paginate);
                $items = $response->items();
                $items = $this->applyInternalMutations($items);
                $items = $this->applyExternalMutations($items);
                $items = $this->applyDependencies($items);
                $items = $this->parseMany($items, $request);
                return $this->paginatedSuccess($response);
            }else{
                $count = $items->count();
                if($count <= $this->maxResults){
                    $items = $items->get();
                    $items = $this->applyInternalMutations($items);
                    $items = $this->applyExternalMutations($items);
                    $items = $this->applyDependencies($items);
                    $items = $this->parseMany($items, $request);
                    return $this->success($items);
                }else{
                    if($request->has('limit')){
                        $items = $items->get();
                        $items = $this->applyInternalMutations($items);
                        $items = $this->applyExternalMutations($items);
                        $items = $this->applyDependencies($items);
                        $items = $this->parseMany($items, $request);
                        return $this->success($items);
                    }else{
                        $response = $items->paginate($this->maxResults)->appends(['total' => $count]);
                        $items = $response->items();
                        $items = $this->applyInternalMutations($items);
                        $items = $this->applyExternalMutations($items);
                        $items = $this->applyDependencies($items);
                        $items = $this->parseMany($items, $request);

                        return $this->paginatedSuccess($response);
                    }
                }
            }
        }catch(\Exception $ex){
            //return $ex;
            return $this->serverError('Wrong filters provided. Please check documentation');
        }
    }

    public function parseSingle($item, $request){
        $currentAttributes = $item->getAttributes();
        try{
            if($request->has('columns')){
                $columns = explode(",",str_replace(' ', '', $request->get('columns')));
                foreach($currentAttributes as $currentAttributeKey => $currentAttributeValue){
                    $shouldRemove = true;
                    foreach($columns as $column){
                        if($currentAttributeKey == $column){
                            $shouldRemove = false;
                        }
                    }
                    if($shouldRemove){
                        unset($item[$currentAttributeKey]);
                    }
                }
            }
            return $item;
        }catch(\Exception $ex){
            return $this->serverError('Wrong filters provided. Please check documentation');
        }
    }

    public function parseMany($items, $request){
        $return = [];
        if(is_array($items)){
            foreach($items as $item){
                $this->parseSingle($item, $request);
            }
        }
        return $items;

    }
}