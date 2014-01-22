<?php namespace Franzose\ClosureTable;

use \Franzose\ClosureTable\Contracts\ClosureTableInterface;

/**
 * Class ClosureTable
 * @package Franzose\ClosureTable
 */
class ClosureTable extends \Eloquent implements ClosureTableInterface {

    /**
     * @var string
     */
    protected $table = 'entities_closure';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * Check if model is a top level one (i.e. has no ancestors).
     *
     * @param null $id
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function isRoot($id = null)
    {
        if ( ! is_null($id) && ! is_int($id))
        {
            throw new \InvalidArgumentException('`id` argument must be of type int.');
        }

        $id = (is_int($id) ?: $this->getKey());

        return !!$this->where(static::DESCENDANT, '=', $id)
            ->where(static::DEPTH, '>', 0)
            ->count() == 0;
    }

    /**
     * Inserts new node into closure table.
     *
     * @param int $ancestorId
     * @param int $descendantId
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function insertNode($ancestorId, $descendantId)
    {
        if ( ! is_int($ancestorId) || ! is_int($descendantId))
        {
            throw new \InvalidArgumentException('`ancestorId` and `descendantId` arguments must be of type int.');
        }

        $t = $this->table;
        $ak = static::ANCESTOR;
        $dk = static::DESCENDANT;
        $dpk = static::DEPTH;

        \DB::transaction(function() use($t, $ak, $dk, $dpk, $ancestorId, $descendantId){
            $rawTable = \DB::getTablePrefix().$t;

            $query = "
                SELECT tbl.{$ak} as {$ak}, {$descendantId} as {$dk}, tbl.{$dpk}+1 as {$dpk}
                FROM {$rawTable} AS tbl
                WHERE tbl.{$dk} = {$ancestorId}
                UNION ALL
                SELECT {$descendantId}, {$descendantId}, 0
            ";

            $results = \DB::select($query);
            array_walk($results, function(&$item){ $item = (array)$item; });

            \DB::table($t)->insert($results);
        });
    }

    /**
     * Make a node a descendant of another ancestor or makes it a root node.
     *
     * @param int $ancestorId
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function moveNodeTo($ancestorId = null)
    {
        if ( ! is_null($ancestorId) && ! is_int($ancestorId))
        {
            throw new \InvalidArgumentException('`ancestor` argument must be of type int.');
        }

        $t   = $this->table;
        $ak  = static::ANCESTOR;
        $dk  = static::DESCENDANT;
        $dpk = static::DEPTH;

        $thisAncestorId = $this->{$ak};
        $thisDescendantId = $this->{$dk};

        // Prevent constraint collision
        if ( ! is_null($ancestorId) && $thisAncestorId === $ancestorId)
        {
            return false;
        }

        $this->unbindRelationships();

        // Since we have already unbound the node relationships,
        // given null ancestor id, we have nothing else to do,
        // because now the node is already root.
        if (is_null($ancestorId))
        {
            return false;
        }

        \DB::transaction(function() use($ak, $dk, $dpk, $t, $ancestorId, $thisDescendantId){
            $query = "
                SELECT supertbl.{$ak}, subtbl.{$dk}, supertbl.{$dpk}+subtbl.{$dpk}+1 as {$dpk}
                FROM {$t} as supertbl
                CROSS JOIN {$t} as subtbl
                WHERE supertbl.{$dk} = {$ancestorId}
                AND subtbl.{$ak} = {$thisDescendantId}
            ";

            $results = \DB::select($query);
            array_walk($results, function(&$item){ $item = (array)$item; });

            \DB::table($t)->insert($results);
        });
    }

    /**
     * Unbindes current relationships.
     */
    protected function unbindRelationships()
    {
        $descendant = $this->{static::DESCENDANT};

        $ancestorsIds = \DB::table($this->table)
            ->where(static::DESCENDANT, '=', $descendant)
            ->where(static::ANCESTOR, '<>', $descendant)
            ->lists(static::ANCESTOR);

        if (count($ancestorsIds))
        {
            \DB::table($this->table)
                ->whereIn(static::ANCESTOR, $ancestorsIds)
                ->where(static::DESCENDANT, '=', $descendant)
                ->delete();
        }
    }

    /**
     * @return string
     */
    public function getQualifiedAncestorColumn()
    {
        return $this->getTable().'.'.static::ANCESTOR;
    }

    /**
     * @return string
     */
    public function getQualifiedDescendantColumn()
    {
        return $this->getTable().'.'.static::DESCENDANT;
    }

    /**
     * @return string
     */
    public function getQualifiedDepthColumn()
    {
        return $this->getTable().'.'.static::DEPTH;
    }
} 