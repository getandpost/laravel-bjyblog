<?php

namespace App\Http\Controllers\Home;

use App\Http\Requests\Comment\Store;
use App\Models\Category;
use App\Models\Article;
use App\Models\Chat;
use App\Models\Comment;
use App\Models\OauthUser;
use App\Models\Tag;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cache;
use App;
use Agent;
use Symfony\Component\Debug\Exception\FatalThrowableError;;

class IndexController extends Controller
{
    /**
     * 首页
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function index()
	{
	    // 获取文章列表数据
        $article = Article::select('id', 'category_id', 'title', 'author', 'description', 'cover', 'created_at')
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $config = cache('config');
        $head = [
            'title' => $config->get('WEB_TITLE'),
            'keywords' => $config->get('WEB_KEYWORDS'),
            'description' => $config->get('WEB_DESCRIPTION'),
        ];
        $assign = [
            'category_id' => 'index',
            'article' => $article,
            'head' => $head,
            'tagName' => ''
        ];
		return view('home.index.index', $assign);
	}

    /**
     * 文章详情
     *
     * @param         $id
     * @param Request $request
     * @param Comment $commentModel
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|void
     * @throws \Exception
     */
    public function article($id, Request $request, Comment $commentModel)
    {
        // 获取文章数据
        $data = Article::with(['category', 'tags'])->find($id);
        if (is_null($data)) {
            return abort(404);
        }
        // 同一个用户访问同一篇文章每天只增加1个访问量  使用 ip+id 作为 key 判别
        $ipAndId = 'articleRequestList'.$request->ip().':'.$id;
        if (!Cache::has($ipAndId)) {
            cache([$ipAndId => ''], 1440);
            // 文章点击量+1
            $data->increment('click');
        }

        // 获取上一篇
        $prev = Article::select('id', 'title')
            ->orderBy('created_at', 'asc')
            ->where('id', '>', $id)
            ->limit(1)
            ->first();

        // 获取下一篇
        $next = Article::select('id', 'title')
            ->orderBy('created_at', 'desc')
            ->where('id', '<', $id)
            ->limit(1)
            ->first();

        // 获取评论
        $comment = $commentModel->getDataByArticleId($id);
        $category_id = $data->category->id;
        $assign = compact('category_id', 'data', 'prev', 'next', 'comment');
        return view('home.index.article', $assign);
    }

    /**
     * 获取分类下的文章
     *
     * @param $id
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|void
     */
    public function category($id)
    {
        // 获取分类数据
        $category = Category::select('id', 'name', 'keywords', 'description')
            ->where('id', $id)
            ->first();
        if (is_null($category)) {
            return abort(404);
        }
        // 获取分类下的文章
        $article = $category->articles()
            ->orderBy('created_at', 'desc')
            ->with('tags')
            ->paginate(10);
        // 为了和首页共用 html ； 此处手动组合分类数据
        if ($article->isNotEmpty()) {
            $article->setCollection(
                collect(
                    $article->items()
                )->map(function ($v) use ($category) {
                    $v->category = $category;
                    return $v;
                })
            );
        }

        $head = [
            'title' => $category->name,
            'keywords' => $category->keywords,
            'description' => $category->description,
        ];
        $assign = [
            'category_id' => $id,
            'article' => $article,
            'tagName' => '',
            'title' => $category->name,
            'head' => $head
        ];
        return view('home.index.index', $assign);
    }

    /**
     * 获取标签下的文章
     *
     * @param $id
     *
     * @return \Illuminate\Contracts\View\Factory
     */
    public function tag($id)
    {
        // 获取标签
        $tag = Tag::select('id', 'name')->where('id', $id)->first();
        if (is_null($tag)) {
            return abort(404);
        }
        // TODO 不取 markdown 和 html 字段
        // 获取标签下的文章
        $article = $tag->articles()
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title' => $tag->name,
            'keywords' => '',
            'description' => '',
        ];
        $assign = [
            'category_id' => 'index',
            'article' => $article,
            'tagName' => $tag->name,
            'title' => $tag->name,
            'head' => $head
        ];
        return view('home.index.index', $assign);
    }

    /**
     * 随言碎语
     *
     * @return mixed
     */
    public function chat()
    {
        $chat = Chat::orderBy('created_at', 'desc')->get();
        $assign =[
            'category_id' => 'chat',
            'chat' => $chat,
            'title' => '随言碎语',
        ];
        return view('home.index.chat', $assign);
    }

    /**
     * 开源项目
     *
     * @return mixed
     */
    public function git()
    {
        $assign = [
            'category_id' => 'git',
            'title' => '开源项目',
        ];
        return view('home.index.git', $assign);
    }

    /**
     * 文章评论
     *
     * @param Store     $request
     * @param Comment   $commentModel
     * @param OauthUser $oauthUserModel
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function comment(Store $request, Comment $commentModel, OauthUser $oauthUserModel)
    {
        $data = $request->only('content', 'article_id', 'pid');
        // 获取用户id
        $userId = session('user.id');
        // 如果用户输入邮箱；则将邮箱记录入oauth_user表中
        $email = $request->input('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            // 修改邮箱
            $oauthUserMap = [
                'id' => $userId
            ];
            $oauthUserData = [
                'email' => $email
            ];
            $oauthUserModel->updateData($oauthUserMap, $oauthUserData);
            session(['user.email' => $email]);
        }
        // 存储评论
        $id = $commentModel->storeData($data, false);
        // 更新缓存
        Cache::forget('common:newComment');
        return ajax_return(200, ['id' => $id]);
    }

    /**
     * 检测是否登录
     */
    public function checkLogin()
    {
        if (empty(session('user.id'))) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * 搜索文章
     *
     * @param Request $request
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function search(Request $request, Article $articleModel){
        // 禁止蜘蛛抓取搜索页
        if (Agent::isRobot()) {
            abort(404);
        }

        $wd = clean($request->input('wd'));

        $id = $articleModel->searchArticleGetId($wd);

        // 获取文章列表数据
        $article = Article::select('id', 'category_id', 'title', 'author', 'description', 'cover', 'created_at')
            ->whereIn('id', $id)
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title' => $wd,
            'keywords' => '',
            'description' => '',
        ];
        $assign = [
            'category_id' => 'index',
            'article' => $article,
            'tagName' => '',
            'title' => $wd,
            'head' => $head
        ];
        return view('home.index.index', $assign);
    }

    /**
     * feed
     *
     * @return \Illuminate\Support\Facades\View
     */
    public function feed()
    {
        // 获取文章
        $article = Cache::remember('feed:article', 10080, function () {
            return Article::select('id', 'author', 'title', 'description', 'html', 'created_at')
                ->latest()
                ->get();
        });
        $feed = App::make("feed");
        $feed->title = '白俊遥';
        $feed->description = '白俊遥博客';
        $feed->logo = 'https://baijunyao.com/uploads/avatar/1.jpg';
        $feed->link = url('feed');
        $feed->setDateFormat('carbon');
        $feed->pubdate = $article->first()->created_at;
        $feed->lang = 'zh-CN';
        $feed->ctype = 'application/xml';

        foreach ($article as $v)
        {
            $feed->add($v->title, $v->author, url('article', $v->id), $v->created_at, $v->description);
        }
        return $feed->render('atom');
    }

    /**
     * 用于做测试的方法
     */
    public function test()
    {

    }


}
