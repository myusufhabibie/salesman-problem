<?php

namespace App\Http\Controllers;

use App\Imports\StoresImport;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\DataTables;

class SalesmanController extends Controller
{
    protected $stores_list;
    
    public function __construct()
    {              
        $_stores = Excel::toArray(new StoresImport, 'stores.csv');
        $this->stores_list = $_stores[0];
    }

    function initializeCentroids($stores, $k) {
        $centroids = [];
        $indices = array_rand($stores, $k);
        foreach ($indices as $index) {
            $centroids[] = ['latitude' => $stores[$index]['latitude'], 'longitude' => $stores[$index]['longitude']];
        }
        return $centroids;
    }

    function assignStoresToClusters($stores, $centroids, $cluster_size) {
        $clusters = array_fill(0, count($centroids), []);
        foreach ($stores as $store) {
            $distances = array_map(function($centroid) use ($store) {
                return sqrt(pow($centroid['latitude'] - $store['latitude'], 2) + pow($centroid['longitude'] - $store['longitude'], 2));
            }, $centroids);
            $minIndex = array_search(min($distances), $distances);
            $clusters[$minIndex][] = $store;
        }
        $transfers = [];
        //even the clusters to roughly same size as cluster size
        array_walk($clusters, function(&$cluster, $no_cluster) use (&$transfers, $centroids, $cluster_size){
            array_walk($cluster, function(&$store, $key) use ($centroids, $no_cluster){
                $store['distance'] = sqrt(pow($centroids[$no_cluster]['latitude'] - $store['latitude'], 2) + pow($centroids[$no_cluster]['longitude'] - $store['longitude'], 2));
            });
            //sorting the nearest store to the centorids
            usort($cluster, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });
            array_push($transfers, ...array_slice($cluster, $cluster_size)); //move the excess stores to transfer list
            $cluster = array_slice($cluster, 0, $cluster_size); //trimming down the cluster
        });
        //transfering stores to vacant clusters
        foreach ($transfers as $idx => $store) {
            $distances = [];
            foreach($centroids as $key => $centroid){
                if(count($clusters[$key]) < $cluster_size){
                    $distances[$key] = sqrt(pow($centroid['latitude'] - $store['latitude'], 2) + pow($centroid['longitude'] - $store['longitude'], 2));
                }
            }
            $minIndex = array_search(min($distances), $distances);
            $clusters[$minIndex][] = $store;
            unset($transfers[$idx]);
        }
        return $clusters;
    }  
        
    function updateCentroids($clusters) {
        $centroids = [];
        foreach ($clusters as $cluster) {
            $centroid = ['latitude' => 0, 'longitude' => 0];
            foreach ($cluster as $point) {
                $centroid['latitude'] += $point['latitude'];
                $centroid['longitude'] += $point['longitude'];
            }
            $centroid['latitude'] = count($cluster) > 0 ? $centroid['latitude'] / count($cluster) : 0 ;
            $centroid['longitude'] = count($cluster) > 0 ? $centroid['longitude'] / count($cluster) : 0 ;
            $centroids[] = $centroid;
        }
        return $centroids;
    }
    
    function sameSizeKMeans($stores, $k) {
        $centroids = $this->initializeCentroids($stores, $k);
        $clusters = [];
        $cluster_size = intval(ceil(count($stores) / $k));
        for ($i = 0; $i < 100; $i++) {
            $clusters = $this->assignStoresToClusters($stores, $centroids, $cluster_size);
            $centroids = $this->updateCentroids($clusters);
        }
        return $clusters;
    }
    
    public function getSalesSchedule(Request $request){
        //number of salesperson
        $_salesperson = 10;
        //grouping stores into clusters
        $stores_cluster = $this->sameSizeKMeans($this->stores_list, $_salesperson);
        //initialize every stores last visit date and next visit date
        array_walk($stores_cluster, function(&$cluster, $sales){
            array_walk($cluster, function(&$store, $no_store){
                $store['last_visit'] = '';
                $store['next_visit'] = '';
            });
        });
        $this->stores_list = $stores_cluster;
        $schedule = []; //array for storing schedules
        $start = Carbon::createFromDate('2024','10','1');
        $end = Carbon::createFromDate('2024','10','31');
        $periode = CarbonPeriod::create($start, '1 day', $end);
        for($i = 0; $i < $_salesperson; $i++){ //loop through salesperson
            $_row = [];
            $_row['sales_name'] = 'Sales '.($i+1);
            foreach($periode as $date){
                if($date->isSunday()){
                    continue;
                }
                $_row[$date->format('d-m-Y')] =  $this->getDailySchedule($i, $date->format('Y-m-d'));//generate schedule for the day to the salesperson
            }
            $schedule[] = $_row;
        }
        $date_column = [];
        foreach($periode as $date){
            if($date->isSunday()){
                continue;
            }
            $date_column[] = $date->format('d-m-Y');
        }
        // dd($schedule);
        return DataTables::of($schedule)->rawColumns($date_column)->make(true);
        // return response()->json($schedule);
    }

    public function getDailySchedule($salesperson, $curr_date){
        $list_stores['routes'] = [];
        $list_stores['visitable_store'] = array_filter($this->stores_list[$salesperson], function($store) use($curr_date){
            return ($store['last_visit'] == '' || $store['next_visit'] == $curr_date);
        });
        $iteration = 0;
        $point = ['latitude' => -7.9826, 'longitude' => 112.6308]; //starting point
        while($iteration < ceil(count($this->stores_list[$salesperson]) / 6) && count($list_stores['visitable_store']) > 0){
            $list_stores = $this->getRoute($list_stores, $point);
            $point = $list_stores['routes'][$iteration]; //moving current point to just selected next point
            $iteration++;
        }
        $this->updateVisitation($salesperson, $list_stores['routes'], $curr_date); //update the visited stores date
        return implode("<br>", array_column($list_stores['routes'],'name'));
    }

    public function getRoute($list_stores, $point){
        $_route = $list_stores['routes'];
        $_visitables = $list_stores['visitable_store'];

        //finding nearest point after the current point
        $distances = array_map(function($_visitable) use ($point) {
            return sqrt(pow($_visitable['latitude'] - $point['latitude'], 2) + pow($_visitable['longitude'] - $point['longitude'], 2));
        }, $_visitables);
        $minIndex = array_search(min($distances), $distances);
        $_route[] = $_visitables[$minIndex];
        unset($_visitables[$minIndex]); //removing selected point from the visitable stores list
        return ['routes' => $_route, 'visitable_store' => $_visitables];
    }

    public function updateVisitation($salesperson, $visited_stores, $curr_date){  
        $visited_stores_code = array_column($visited_stores, 'code');
        array_walk($this->stores_list[$salesperson], function(&$store) use($visited_stores_code, $curr_date){
            if(in_array($store['code'], $visited_stores_code)){
                if(strtolower($store['final_cycle']) == 'weekly'){                
                    $next_visit = Carbon::createFromFormat('Y-m-d', $curr_date)->addWeek();
                }
                else if(strtolower($store['final_cycle']) == 'biweekly'){                
                    $next_visit = Carbon::createFromFormat('Y-m-d', $curr_date)->addWeeks(2);
                }
                else{
                    $next_visit = Carbon::createFromFormat('Y-m-d', $curr_date)->addMonth();
                }
                //update the next visit and last visit to the visited store
                $store['last_visit'] = $curr_date;
                $store['next_visit'] = $next_visit->format('Y-m-d');
            }
        });
    }

}
