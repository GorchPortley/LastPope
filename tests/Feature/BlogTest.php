<?php

use App\Models\User;
use Wave\Category;
use Wave\Post;

function blogPost(array $overrides = []): Post
{
    $category = Category::firstOrCreate(['slug' => 'guides'], ['name' => 'Guides']);

    return Post::create($overrides + [
        'author_id' => User::factory()->create(['name' => 'Ada Builder'])->id,
        'category_id' => $category->id,
        'title' => 'Adding a driver',
        'slug' => 'adding-a-driver-'.uniqid(),
        'body' => '<p>Check the database first.</p>',
        'status' => 'PUBLISHED',
    ]);
}

it('lists published posts with the placeholder picture and hides drafts', function () {
    blogPost(['title' => 'Live post']);
    blogPost(['title' => 'Secret draft', 'status' => 'DRAFT']);

    $this->get('/blog')->assertOk()
        ->assertSee('Live post')
        ->assertDontSee('Secret draft')
        ->assertSee('images/blog/post-placeholder.svg', false);

    $this->get('/blog/guides')->assertOk()
        ->assertSee('Live post')
        ->assertDontSee('Secret draft');
});

it('shows a post with its author, and drafts only to admins', function () {
    $post = blogPost();
    $draft = blogPost(['title' => 'Unfinished', 'status' => 'DRAFT']);

    $this->get('/blog/guides/'.$post->slug)->assertOk()
        ->assertSee('Adding a driver')
        ->assertSee('By Ada Builder')
        ->assertSee('Check the database first.', false);

    $this->get('/blog/guides/'.$draft->slug)->assertNotFound();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get('/blog/guides/'.$draft->slug)->assertOk()->assertSee('only admins can see this');
});

it('uses the uploaded picture when a post has one', function () {
    $post = blogPost(['image' => 'posts/cover.jpg']);

    expect($post->image())->toEndWith('/storage/posts/cover.jpg');
});

it('sets the post body in the same text styles as design and driver pages', function () {
    $post = blogPost(['body' => '<h2>Section</h2><p>Body text.</p>']);

    $this->get('/blog/guides/'.$post->slug)->assertOk()
        ->assertSee('class="sd-rich" data-post-body', false)
        ->assertDontSee('prose-lg', false);
});
