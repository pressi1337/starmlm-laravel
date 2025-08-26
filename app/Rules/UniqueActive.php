<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class UniqueActive implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    protected $id;
    protected $table;
    protected $column;
    protected $filter;
    public function __construct($table,$column,$id=null,$filter=[])
    {
        $this->table=$table;
        $this->column=$column;
        $this->id=$id;
        $this->filter=$filter;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $query=DB::table($this->table)
        ->where($this->column, $value)
        ->where('is_deleted', 0);
        if (!is_null($this->id)) {
            $query->where('id', '!=', $this->id);
        }
        foreach ($this->filter as $column => $filterValue) {
            $query->where($column, $filterValue);
        }
        $count = $query->count();
        return $count === 0;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute is already taken.';
    }
}
