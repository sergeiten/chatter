<?php

namespace DevDojo\Chatter\Controllers;

use Auth;
use DevDojo\Chatter\Models\Models;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as Controller;

class ChatterController extends Controller
{
    public function index(Request $request, $slug = '')
    {
        $discussions = $this->getDiscussions($slug, $request->get('query'), config('chatter.paginate.num_of_results'));

        $categories = Models::category()->all();
        $chatter_editor = config('chatter.editor');

        if ($chatter_editor == 'simplemde') {
            // Dynamically register markdown service provider
            \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
        }

        return view('chatter::home', compact('discussions', 'categories', 'chatter_editor'));
    }

    private function getDiscussions($slug = '', $search, $pagination_results) {
        $query = Models::discussion()
            ->with('user')
            ->with('post')
            ->with('postsCount')
            ->with('category');

        if ($query) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%');
                $q->orWhereHas('post', function($q) use ($search) {
                    $q->where('body', 'like', '%' . $search . '%');
                });
            });
        }

        if (!empty($slug)) {
            $category = Models::category()->where('slug', '=', $slug)->first();

            if ($category) {
                $query->where('chatter_category_id', '=', $category->id);
            }
        }

        return $query
            ->orderBy('created_at', 'DESC')
            ->paginate($pagination_results);
    }

    public function login()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.login').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please create an account before posting.');
        }
    }

    public function register()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.register').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please register for an account.');
        }
    }
}
