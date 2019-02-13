<?php

namespace App\Http\Traits;

use App\Connection;
use App\Events\GazingleCrud\ModelCreatedEvent;
use App\Events\GazingleCrud\ModelDeletedEvent;
use App\Events\GazingleCrud\ModelPurgedEvent;
use App\Events\GazingleCrud\ModelRestoredEvent;
use App\Events\GazingleCrud\ModelUpdatedEvent;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

/**
 * Trait GazingleCrud
 * @package App\Http\Traits
 */
trait GazingleCrud {

    use GazingleApi;
    use GazingleConnect;

    public $item;
    public $items;
    public $model;
    public $connectionModel;
    public $user;


    /**
     * Search in model by filters.
     *
     * @return Builder
     */
    protected function _search(Request $request){
        $model = self::MODEL;
        $this->item = new $model;
        $items = $this->item->whereNotNull('id');
        $items = $this->_searchInModel($this->item, $items);
        return $items;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request){
        $model = self::MODEL;
        $this->item = new $model();
        if($this->_isSearchRequest($this->item)){
            $items = $this->_search($request);
        }else{
            $items = $this->item;
        }
        return $this->returnParsed($items, $request);
    }

    /**
     * Set current item as DB entity. Ignores soft-deletes
     *
     * @return Model
     */
    protected function _getById($id){
        $model = self::MODEL;
        $this->item = new $model();
        $this->item = $model::withTrashed()->findOrFail($id);
        return $this->item;
    }

    /**
     * Display a specific resource.
     *
     * @return Response
     */
    public function get(Request $request, $id){
        $this->item = $this->_getById($id);
        $this->item =  $this->applyInternalMutations([$this->item])[0];
        $this->item =  $this->applyExternalMutations([$this->item])[0];
        $this->item =  $this->applyDependencies([$this->item])[0];
        $this->item = $this->parseSingle($this->item, $request);
        return $this->success($this->item);
    }

    /**
     * Store a specific resource.
     * @return Response
     */
    public function store(Request $request){
        $model = self::MODEL;
        $this->item = new $model();
        $this->validate($request, $this->item->createRules());
        $this->item = $this->item->create($request->all());
        $this->item = $this->_getById($this->item->id);
        $this->item = $this->applyInternalMutations([$this->item])[0];
        $this->item =  $this->applyExternalMutations([$this->item])[0];
        $this->item =  $this->applyDependencies([$this->item])[0];
        event(new ModelCreatedEvent($this->item));
        $this->item = $this->parseSingle($this->item, $request);
        return $this->success($this->item);
    }

    /**
     * Update a specific resource.
     * @return Response
     */
    public function update(Request $request, $id){
        $this->item = $this->_getById($id);
        $this->validate($request, $this->item->updateRules());
        $this->item->fill($request->all());
        $allMeta = $this->item->meta;
        foreach($this->item->meta as $metaKey => $metaValue){
            if(array_key_exists($metaKey, $request->all())){
                $allMeta[$metaKey] = $request[$metaKey];
                $this->item->meta = $allMeta;
            }
        }
        $this->item->save();
        $this->item = $this->applyInternalMutations([$this->item])[0];
        $this->item =  $this->applyExternalMutations([$this->item])[0];
        $this->item =  $this->applyDependencies([$this->item])[0];
        event(new ModelUpdatedEvent($this->item));
        $this->item = $this->parseSingle($this->item, $request);
        return $this->get($request, $this->item->id);
    }

    /**
     * Soft deletes a specific resource.
     * @return Response
     */
    public function delete(Request $request, $id){
        $this->item = $this->_getById($id);
        $this->item->delete();
        $this->item = $this->applyInternalMutations([$this->item])[0];
        $this->item =  $this->applyExternalMutations([$this->item])[0];
        $this->item =  $this->applyDependencies([$this->item])[0];
        event(new ModelDeletedEvent($this->item));
        $this->item = $this->parseSingle($this->item, $request);
        return $this->success($this->item);
    }

    /**
     * Restore a soft-deleted specific resource.
     * @ToDo deal with restore UNIQUE ID collision
     * @return Response
     */
    public function restore(Request $request, $id){
        $this->item = $this->_getById($id);
        $this->item->restore();
        $this->item = $this->applyInternalMutations([$this->item])[0];
        $this->item =  $this->applyExternalMutations([$this->item])[0];
        $this->item =  $this->applyDependencies([$this->item])[0];
        event(new ModelRestoredEvent($this->item));
        $this->item = $this->parseSingle($this->item, $request);
        return $this->success($this->item);
    }

    /**
     * Purge a specific resource.
     * @return Response
     */
    public function destroy(Request $request, $id){
        $this->item = $this->_getById($id);
        try{
            $this->item->forceDelete();
            $this->item = $this->applyInternalMutations([$this->item])[0];
            $this->item =  $this->applyExternalMutations([$this->item])[0];
            $this->item =  $this->applyDependencies([$this->item])[0];
            event(new ModelPurgedEvent($this->item));
            $this->item = $this->parseSingle($this->item, $request);
        }catch(QueryException $exception){
            return $this->wrongData('Unable to purge item. Please Detach all connections first');
        }
        return $this->success($this->item);
    }

    /**
     * Get specific resource connections. By default gets all except detached and without object connected
     * @param with_detached boolean - includes detached connections (example: get lead equipment history)
     * @param include_objects boolean - includes detached connections (example: get lead equipment history)
     * @return Response
     */
    public function getConnections(Request $request, $id){
        $connectionModel = self::CONNECTION_MODEL;
        $this->connectionModel = new $connectionModel;
        $connections = $this->connectionModel->where('item_id', $id);
        if(!$request->with_detached){
            $connections = $connections->whereNull('detached_at');
        }
        if($request->service){
            $connections = $connections->where('service', $request->service);
        }
        $connections = $connections->get();

        if($request->include_objects){
            $includedServices = [];
            $includedObjects = [];
            foreach ($connections as $connectionItem){
                if(!isset($includedServices[$connectionItem->service])){
                    $includedServices[$connectionItem->service] = [];
                }
                $includedServices[$connectionItem->service][] = $connectionItem->service_id;
            }
            foreach($includedServices as $serviceKey => $ids){
                try{
                    $serviceResponse = $this->indexFrom($serviceKey, ['id' => implode(',', $ids)]);
                    if(isset($serviceResponse['data']))
                        $serviceResponse = $serviceResponse['data'];
                }catch(\Exception $exception){
                    return $this->wrongData('Something went wrong with connection to '.$serviceKey.'. Please check out your connections table');
                }
                $includedObjects[$serviceKey] = $serviceResponse;
            }
            return $this->success(['connections' => $connections, 'objects' => $includedObjects]);
        }
        return $this->success($connections);
    }


    /**
     * Get specific connection item from DB entity.
     *
     * @return Connection
     */
    protected function _getConnectionItem($service, $service_id){
        $item = Connection::where('item_id', $this->item->id)
            ->where('service_id', $service_id)
            ->where('service', $service)
            ->orderBy('created_at', 'desc')
            ->first();
        return $item;
    }


    /**
     * Attach specific resource to a service.
     * ToDo: replace temporary bullshit with actual getUser().
     * @return Response
     */
    public function attach(Request $request, $id){
        /*
         * Temporary crap, until we get a jwt ready
         */
        $this->item = $this->_getById($id);
        $request->merge(['item_id' => $id])
            ->merge(['user_id' => 1]);
        $this->validate($request, [
            'item_id' => 'required|integer',
            'service_id' => 'required|integer',
            'user_id' => 'required|integer',
            'service' => 'required|string',
            'attached_at' => 'date',
        ]);
        if(!$request->has('attached_at')){
            $request->merge(['attached_at' => Carbon::now()]);
        }
        try{
            $historyItem = $this->_getConnectionItem($request->service, $request->service_id);
            if(!$historyItem || $historyItem->detached_at){
                $result = $this->item->connections()->create($request->except('detached_at'));
                return $this->success($result);
            }
            return $this->wrongData('Item is already attached');
        }catch(\Exception $e){
            return $this->serverError('Something went wrong. Please check a documentation');
        }
    }

    /**
     * Detach specific resource to a service.
     * @return Response
     * ToDo: replace temporary bullshit with actual getUser().
     */
    public function detach(Request $request, $id){
        /*
         * Temporary crap, until we get a jwt ready
         */
        $request->merge(['item_id' => $id])
            ->merge(['user_id' => 1]);
        $this->item = $this->_getById($id);
        $this->validate($request, [
            'item_id' => 'required|integer',
            'service_id' => 'required|integer',
            'user_id' => 'required|integer',
            'service' => 'required|string',
            'detached_at' => 'date',
        ]);
        try{
            $result = $this->_getConnectionItem($request->service, $request->service_id);
            if($result){
                if($result->detached_at)
                    return $this->wrongData('Item is already detached');
            }else{
                return $this->wrongData('Item is not attached');
            }
            $result->fill(['detached_at' => ($request->has('detached_at'))? $request->detached_at : Carbon::now()])->save();

            return $this->success($result);
        }catch(QueryException $e){
            return $this->serverError('Something went wrong. Please check a documentation');
        }
    }
}