<?php

namespace Modules\Isearch\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Modules\Iblog\Entities\Post;
use Modules\Isearch\Repositories\Collection;
use Modules\Isearch\Repositories\SearchRepository;
use Modules\Core\Repositories\Eloquent\EloquentBaseRepository;
use Laracasts\Presenter\PresentableTrait;
use Modules\Isearch\Transformers\SearchItemTransformer;
use Illuminate\Support\Str;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Container\Container;

class EloquentSearchRepository extends EloquentBaseRepository implements SearchRepository
{

  public function whereSearch($searchphrase)
  {
    return $this->posts->where('title', 'LIKE', "%{$searchphrase}%")
      ->orWhere('description', 'LIKE', "%{$searchphrase}%")
      ->orderBy('created_at', 'DESC')->paginate(12);
  }

  public function getItemsBy($params)
  {
   
    $filter = $params->filter;
    $minCharactersSearch = setting("isearch::minSearchChars");
    
    $params->filter->minCharactersSearch = setting("isearch::minSearchChars",null,3);
    
    //Default Repository to Search if not exist 
    //Example: Tusanagustin - It is in the Home and then it goes to a search index
    if(!isset($filter->repository)){
      $settingRepos = setting("isearch::repoSearch");
      if(!is_null($settingRepos)){
        $repositories = json_decode($settingRepos);
        $filter->repository = $repositories[0]; // Take the first
      }
    }

    if(isset($filter->repository)){

      //Sometimes, it came as an array, it is validated so that it always takes only 1
      if(is_array($filter->repository))
        $filter->repository = $filter->repository[0];

      //Implementation Example: Tusanagustin
      if($filter->repository=="all"){

        return $this->getDataToAll($params,$filter,$minCharactersSearch);

      }else{

        //Implementation - One repository
        try {

          //Get items
          $repository = app($filter->repository);
          $items = $repository->getItemsBy($params);
          
        } catch (\Exception $e) {
          //dd($e);
          \Log::info("Isearch::SearchRepository | getItemsBy error:".$e->getMessage());
        }

        return $items;
      }


    }

  }
  
  private function customPaginate( $results, $showPerPage)
  {
    $pageNumber = Paginator::resolveCurrentPage('page');
    
    $totalPageNumber = $results->count();
    
    return $this->paginator($results->forPage($pageNumber, $showPerPage), $totalPageNumber, $showPerPage, $pageNumber, [
      'path' => Paginator::resolveCurrentPath(),
      'pageName' => 'page',
    ]);
    
  }
  
  /**
   * Create a new length-aware paginator instance.
   *
   * @param  \Illuminate\Support\Collection  $items
   * @param  int  $total
   * @param  int  $perPage
   * @param  int  $currentPage
   * @param  array  $options
   * @return \Illuminate\Pagination\LengthAwarePaginator
   */
  protected static function paginator($items, $total, $perPage, $currentPage, $options)
  {
    return Container::getInstance()->makeWith(LengthAwarePaginator::class, compact(
      'items', 'total', 'perPage', 'currentPage', 'options'
    ));
  }
  
  private function getPaginator($request, $items, $page, $take)
  {
    
    $total = count($items); // total count of the set, this is necessary so the paginator will know the total pages to display
    $offset = ($page - 1) * $take; // get the offset, how many items need to be "skipped" on this page
  
    $items = $items->forPage($page, $take);
    return new LengthAwarePaginator($items, $total, $take, $page, [
      'path' => $request->url(),
      'query' => $request->query()
    ]);
  }

  /*
  * Get repositories from setting and make data
  */
  public function getRepositoriesFromSetting($params){

    // Repositories selected in setting to search
    $repositories = json_decode(setting('isearch::repoSearch'));
    $data = [];

    //All data from repositories
    $repositoriesData = config("asgard.isearch.config.repositories");


    if(!is_null($repositories)){

      $repoDataValues = array_column($repositoriesData, 'value');

      foreach ($repositories as $key => $value) {

        $pos = array_search($value, $repoDataValues);

        $data[] = (object)[
          "id" => $value, // Value in filter select
          "name" => $repositoriesData[$pos]['label'] ?? ""
        ];
      }
    }

    return $data;

  }

  /*
  * get data if option is ALL 
  */
  public function getDataToAll($params,$filter,$minCharactersSearch){

      $results = Collect();

      $take = $params->take;
      $page = $params->page;
      $params->take = $params->page = 0;

      $filter->repositories = json_decode(setting("isearch::repoSearch"));
      
      //There is only 1 option and it is "ALL", then it doesn't exist a repository
      if(count($filter->repositories)==1 && $filter->repositories[0]=="all"){
        
        //All data from config repositories
        $repositoriesData = config("asgard.isearch.config.repositories");

        $filter->repositories = array_column($repositoriesData, 'value');
      }

     
      //Delete option "all" from array
      $pos = array_search("all", $filter->repositories);
      unset($filter->repositories[$pos]); 
      

      !is_array($filter->repositories) ? $filter->repositories = [$filter->repositories] : false;
      foreach ($filter->repositories as $repository) {
        try {

          $repository = app($repository);
          $items = $repository->getItemsBy($params);
          $results = $results->concat($items);
        } catch (\Exception $e) {
          //dd($e);
          \Log::info("Isearch::SearchRepository | getItemsBy error:".$e->getMessage());
        }
      }
      $words = explode(' ', trim($filter->search));
      

      foreach($results as &$result){
        $result->coincidences=0;
        foreach ($words as $index => $word) {
          if(strlen($word)>=$minCharactersSearch){
            $pos = strpos(Str::slug($result->title ?? $result->name ?? ""),Str::slug($word));
            if($pos !== false){
              $result->coincidences+=1;
            }//if pos
            $pos = strpos(Str::slug($result->description ?? $result->body ?? ""),Str::slug($word));
            if($pos !== false){
              $result->coincidences+=1;
            }//if pos
          }//if str len words
        }//foreach words
      }//foreach collection
      
      //Sort by coincidences
      $results=$results->sortByDesc("coincidences");

      $results = $this->getPaginator(request(),$results, $page, $take);

      return $results;
  }

}
