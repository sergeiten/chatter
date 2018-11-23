<?php

namespace DevDojo\Chatter\Controllers;

use Auth;
use Carbon\Carbon;
use DevDojo\Chatter\Events\ChatterAfterNewDiscussion;
use DevDojo\Chatter\Events\ChatterBeforeNewDiscussion;
use DevDojo\Chatter\Helpers\ChatterHelper;
use DevDojo\Chatter\Models\Models;
use Event;
use http\Env\Request;
use Illuminate\Routing\Controller as Controller;
use Validator;

class ChatterDiscussionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        /*$total = 10;
        $offset = 0;
        if ($request->total) {
            $total = $request->total;
        }
        if ($request->offset) {
            $offset = $request->offset;
        }
        $discussions = Models::discussion()->with('user')->with('post')->with('postsCount')->with('category')->orderBy('created_at', 'ASC')->take($total)->offset($offset)->get();*/

        // Return an empty array to avoid exposing user data to the public.
        // This index function is not being used anywhere.
        return response()->json([]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Models::category()->all();

        return view('chatter::discussion.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->request->add(['body_content' => strip_tags($request->body)]);

        $validator = Validator::make($request->all(), [
            'title'               => 'required|min:5|max:255',
            'body_content'        => 'required|min:10',
            'chatter_category_id' => 'required',
        ], [
            'title.required' => '제목을 입력하세요',
            'body_content.required' => '글을 입력하세요',
            'body_content.min' => '글은 최소 :min글자 이상 쓰셔야 합니다',
            'chatter_category_id.required' => '카테고리 선택은 필수사항입니다',
        ]);

        Event::fire(new ChatterBeforeNewDiscussion($request, $validator));
        if (function_exists('chatter_before_new_discussion')) {
            chatter_before_new_discussion($request, $validator);
        }

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user_id = Auth::user()->id;

        if (config('chatter.security.limit_time_between_posts')) {
            if ($this->notEnoughTimeBetweenDiscussion()) {
                $chatter_alert = [
                    'chatter_alert_type' => 'danger',
                    'chatter_alert'      => config('chatter.security.time_between_posts').'분후에 작성이 가능합니다(스팸방지)',
                ];

                return redirect('/'.config('chatter.routes.home'))->with($chatter_alert)->withInput();
            }
        }

        // *** Let's gaurantee that we always have a generic slug *** //
//        $slug = ChatterHelper::hangulSlug($request->title);
//        $slug = str_slug($slug, '-');
//
//        $discussion_exists = Models::discussion()->where('slug', '=', $slug)->first();
//        $incrementer = 1;
//        $new_slug = $slug;
//        while (isset($discussion_exists->id)) {
//            $new_slug = $slug.'-'.$incrementer;
//            $discussion_exists = Models::discussion()->where('slug', '=', $new_slug)->first();
//            $incrementer += 1;
//        }
//
//        if ($slug != $new_slug) {
//            $slug = $new_slug;
//        }

        // create temporary slug
        $slug = md5(time());

        $new_discussion = [
            'title'               => $request->title,
            'chatter_category_id' => $request->chatter_category_id,
            'user_id'             => $user_id,
            'slug'                => $slug,
            'color'               => $request->color,
        ];

        $category = Models::category()->find($request->chatter_category_id);
        if (!isset($category->slug)) {
            $category = Models::category()->first();
        }

        $discussion = Models::discussion()->create($new_discussion);

        // create discussion slug based on its id
        $slug = 'discussion-' . $discussion->id;

        $discussion->slug = $slug;
        $discussion->save();

        $new_post = [
            'chatter_discussion_id' => $discussion->id,
            'user_id'               => $user_id,
            'body'                  => $request->body,
        ];

        if (config('chatter.editor') == 'simplemde'):
            $new_post['markdown'] = 1;
        endif;

        // add the user to automatically be notified when new posts are submitted
        $discussion->users()->attach($user_id);

        $post = Models::post()->create($new_post);

        if ($post->id) {
            Event::fire(new ChatterAfterNewDiscussion($request, $discussion, $post));
            if (function_exists('chatter_after_new_discussion')) {
                chatter_after_new_discussion($request);
            }

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => '성공적으로 글이 생성되었습니다',
            ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => 'Whoops :( There seems to be a problem creating your '.config('chatter.titles.discussion').'.',
            ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$slug)->with($chatter_alert);
        }
    }

    private function notEnoughTimeBetweenDiscussion()
    {
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_discussion = Models::discussion()->where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if (isset($last_discussion)) {
            return true;
        }

        return false;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($category, $slug = null)
    {
        if (!isset($category) || !isset($slug)) {
            return redirect(config('chatter.routes.home'));
        }

        $discussion = Models::discussion()->where('slug', '=', $slug)->firstOrFail();

        $discussion_category = Models::category()->find($discussion->chatter_category_id);
        if ($category != $discussion_category->slug) {
            return redirect(config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$discussion_category->slug.'/'.$discussion->slug);
        }
        // we have to skip the first post (origin)
        // because we get it in separate query
        // the origin post is the first post in the first page
        $skip = 0;
        if (Request::has('page') && Request::get('page') == 1) {
            $skip = 1;
        }
        $posts = Models::post()
            ->with('user')
            ->where('chatter_discussion_id', '=', $discussion->id)
            ->orderBy('created_at', 'ASC')
            ->skip($skip)
            ->paginate(10);

        // get origin post in order to change display UI
        $originPost = Models::post()->where('chatter_discussion_id', '=', $discussion->id)->orderBy('created_at', 'DESC')->first();

        $chatter_editor = config('chatter.editor');

        if ($chatter_editor == 'simplemde') {
            // Dynamically register markdown service provider
            \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
        }

        $discussion->increment('views');
        
        return view('chatter::discussion', compact('discussion', 'posts', 'chatter_editor', 'originPost'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    private function sanitizeContent($content)
    {
        libxml_use_internal_errors(true);
        // create a new DomDocument object
        $doc = new \DOMDocument();

        // load the HTML into the DomDocument object (this would be your source HTML)
        $doc->loadHTML($content);

        $this->removeElementsByTagName('script', $doc);
        $this->removeElementsByTagName('style', $doc);
        $this->removeElementsByTagName('link', $doc);

        // output cleaned html
        return $doc->saveHtml();
    }

    private function removeElementsByTagName($tagName, $document)
    {
        $nodeList = $document->getElementsByTagName($tagName);
        for ($nodeIdx = $nodeList->length; --$nodeIdx >= 0;) {
            $node = $nodeList->item($nodeIdx);
            $node->parentNode->removeChild($node);
        }
    }

    public function toggleEmailNotification($category, $slug = null)
    {
        if (!isset($category) || !isset($slug)) {
            return redirect(config('chatter.routes.home'));
        }

        $discussion = Models::discussion()->where('slug', '=', $slug)->first();

        $user_id = Auth::user()->id;

        // if it already exists, remove it
        if ($discussion->users->contains($user_id)) {
            $discussion->users()->detach($user_id);

            return response()->json(0);
        } else { // otherwise add it
            $discussion->users()->attach($user_id);

            return response()->json(1);
        }
    }
}
