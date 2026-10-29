<?php

namespace Wave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    public $guarded = [];

    public function link(){
    	return url('/blog/' . $this->category->slug . '/' . $this->slug);
    }

    public function user(){
        return $this->belongsTo('\Wave\User', 'author_id');
    }

    /** The post's picture, or the site's woofer placeholder when it has none. */
    public function image(){
        return filled($this->image) ? Storage::url($this->image) : asset('images/blog/post-placeholder.svg');
    }

    public function scopePublished($query){
        return $query->where('status', 'PUBLISHED');
    }

    /** The excerpt if one was written, otherwise the start of the body as plain text. */
    public function teaser(int $length = 160): string{
        $text = filled($this->excerpt) ? $this->excerpt : html_entity_decode(strip_tags((string) $this->body));

        return \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $text)), $length);
    }

    public function category(){
    	return $this->belongsTo('Wave\Category');
    }
}
