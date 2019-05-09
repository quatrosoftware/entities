<?php
/*******************************************************************************
 * Copyright (c) 2019 by Jakub Socha <jsocha@quatrodesign.pl>
 ******************************************************************************/

namespace Jsocha\Entities;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Class BaseRepository
 *
 * @package Jsocha\Entities
 */
abstract class BaseRepository
{
    /**
     * Instancja Encji (klasa)
     *
     * @var string
     */
    protected $entity;
    
    /**
     * Nazwa tabeli w bazie danych
     *
     * @var string
     */
    protected $table = '';
    
    /**
     * Nazwa połączenia z bazą danych
     *
     * @var string
     */
    protected $connection = 'mysql';
    
    /**
     * Zwraca encję po ID
     *
     * @param int $id
     *
     * @return mixed
     */
    public function find(int $id)
    {
        $query = DB::connection($this->getConnection())->table($this->getTable());
        
        $result = (array) $query->find($id);
        
        if ($result) {
            return new $this->entity($result);
        }
        
        return null;
    }
    
    
    /**
     * Zwraca 1 encję wg warunków
     *
     * @param array $conditions
     * @param array $sorting
     *
     * @return mixed
     */
    public function findOneBy(array $conditions = [], array $sorting = [])
    {
        $query = $this->applyFilters(DB::connection($this->getConnection())->table($this->getTable()), $conditions);
        
        foreach ($sorting as $field => $direction) {
            $query->orderBy($field, $direction);
        }
        
        $result = (array) $query->first();
        
        if ($result) {
            return new $this->entity ($result);
        }
        
        return null;
    }
    
    /**
     * Zwraca kolekcję encję wg warunków
     *
     * @param array $conditions
     * @param array $sorting
     * @param int   $limit
     * @param array $relations
     *
     * @return array
     */
    public function findBy(array $conditions = [], array $sorting = [], int $limit = 0, array $relations = [])
    {
        $query = $this->applyFilters(DB::connection($this->getConnection())->table($this->getTable()), $conditions);
        
        foreach ($sorting as $field => $direction) {
            $query->orderBy($field, $direction);
        }
        
        if ($limit > 0) {
            $query->take($limit);
        }
        
        return $this->mapToEntity($this->mergeRelations($query, $relations));
    }
    
    /**
     * Wszystkie encje
     *
     * @return array
     */
    public function all(): array
    {
        $query = DB::connection($this->getConnection())->table($this->getTable())->get()->toArray();
        
        return $this->mapToEntity($query);
    }
    
    /**
     * Zlicza ile jest rekordów spełniających wymagania
     *
     * @param array $conditions
     *
     * @return int
     */
    public function countBy(array $conditions = []): int
    {
        $query = $this->applyFilters(DB::connection($this->getConnection())->table($this->getTable()), $conditions);
        
        return $query->count();
    }
    
    
    /**
     * Podstawowa paginacja
     *
     * @param array $filters
     * @param array $sorting
     * @param int   $currentPage
     * @param int   $perPage
     * @param array $options
     *
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters, array $sorting, int $currentPage, int $perPage = 30, array $relations = [], array $options = []): LengthAwarePaginator
    {
        $getPortion = $this->takePortion($filters, $sorting, $currentPage, $perPage, $relations);
        
        if ($perPage > 0) {
            $totalOffers = $this->countForPagination($filters);
        }
        else {
            $totalOffers = count($getPortion);
        }
        
        return new LengthAwarePaginator($getPortion, $totalOffers, $perPage, $currentPage, $options);
    }
    
    /**
     * Zliczenie rekordów spełniających kryteria
     *
     * @param array $filters
     *
     * @return integer
     */
    public function countForPagination(array $filters): int
    {
        $query = $this->applyFilters(DB::connection($this->getConnection())->table($this->getTable()), $filters);
        
        return $query->select('id')->count();
    }
    
    /**
     * Rekordy z podanego przedziału stronicowego spełniające kryteria
     *
     * @param array $filters
     * @param array $sorting
     * @param int   $page
     * @param int   $perPage
     *
     * @return array
     */
    public function takePortion(array $filters, array $sorting, int $page = 1, int $perPage = 30, array $relations = []): array
    {
        $query = $this->applyFilters(DB::connection($this->getConnection())->table($this->getTable()), $filters);
        
        foreach ($sorting as $sort => $direction) {
            $query->orderBy($sort, $direction);
        }
        
        if ($perPage > 0) {
            $query->take($perPage)->skip(($perPage * $page) - $perPage);
        }
        
        return $this->mapToEntity($this->mergeRelations($query, $relations));
    }
    
    
    /**
     * Aplikacja filtrów na wyniki
     *
     * @param Builder $query
     * @param array   $filters
     *
     * @return Builder
     */
    protected function applyFilters(Builder $query, $filters): Builder
    {
        foreach ($filters as $field => $filter) {
            
            if (is_array($filter)) {
                if ($filter[0] === 'IN') {
                    $query->whereIn($field, $filter[1]);
                }
                elseif ($filter[0] === 'BETWEEN') {
                    $query->whereBetween($field, $filter[1]);
                }
                elseif ($filter[0] === 'RAW') {
                    $query->whereRaw($filter[1]);
                }
                else {
                    $query->where($field, $filter[0], $filter[1]);
                }
            }
            else {
                $query->where($field, $filter);
            }
            
        }
        
        return $query;
    }
    
    /**
     * Mapuje wyniki zapytania na pojedyncze encje w tablicy
     *
     * @param array $query
     *
     * @return array
     */
    protected function mapToEntity(array $query): array
    {
        return $result = array_map(function ($item) {
            return new $this->entity ((array) $item);
        }, $query);
    }
    
    /**
     * Dołączenie relacji(jeśli istnieją)
     *
     * @param Builder $query
     * @param array   $relations
     *
     * @return array
     */
    protected function mergeRelations(Builder $query, array $relations)
    {
        $result = $query->get()->toArray();
        
        if (count($relations) > 0) {
            
            /**
             * Pobieram konfiguracje relacji dla danej encji
             */
            $relationsData = (new $this->entity)->getRelations();
            
            /**
             * Lecimy każdą relację po kolei
             */
            foreach ($relations as $relationKey) {
                /**
                 * Dane relacji z configu Encji
                 */
                $relation = $relationsData[$relationKey];
                
                $relationManager = new RelationsManager($result, $relation);
                
                /**
                 * Relacja 1 do wielu
                 */
                if ($relation['type'] === 'hasMany') {
                    $data = $relationManager->hasMany();
                }
                
                /**
                 * Relacja 1 do 1
                 */
                if ($relation['type'] === 'hasOne') {
                    $data = $relationManager->hasOne();
                }
                
                /**
                 * Dodanie do głównej encji pobranych encji dzieci.
                 */
                $relationLocalKey = $relation['local_key'];
                
                foreach ($result as $i => $row) {
                    $entity = (array) $row;
                    
                    $result[$i]->$relationKey = isset($data[$entity[$relationLocalKey]]) ? $data[$entity[$relationLocalKey]] : ($relation['type'] === 'hasMany' ? [] : null);
                }
                
            }
        }
        
        return $result;
    }
    
    /**
     * Zwraca nazwę tabeli w bazie dla encji z bazy
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * @return string
     */
    public function getConnection(): string
    {
        return $this->connection;
    }
    
    /**
     * @return mixed
     */
    public function getEntity()
    {
        return $this->entity;
    }
    
}