<?php

// Registering middleware to use 
$router->registerMiddleware('auth', \App\Core\Middlewares\AuthMiddleware::class);
// $router->registerMiddleware('adminAuth', \App\Core\Middlewares\AdminMiddleware::class);





// currently auth is admin auth we dont need adminAuth for now later we will see
// Authentication Routes For admin user login
$router->group(['prefix' => 'auth'], function ($router) {





    $router->add([
        'method' => 'POST',
        'url' => 'register',
        'controller' => 'AuthController@register',
        'desc' => 'register a normal user',
        'group' => 'Authentication',
        'params' => [
            'json' => [
                'username' => 'Optional: User name',
                'email' => 'Optional: Email address',
                'password' => 'Optional: Phone number',
                'full_name' => 'Optional: Phone number',
                'role' => 'user | admin | editor',
            ]
        ]
    ]);




    $router->add([
        'method' => 'POST',
        'url' => 'login',
        'controller' => 'AuthController@login',
        'desc' => 'login a normal user',
        'group' => 'Authentication',
        'params' => [
            'json' => [

                'email' => 'string email default(abhinandan@boomlive.in)',
                'password' => 'string password default(abcd)'

            ]
        ]
    ]);



    $router->add([
        'method' => 'POST',
        'url' => 'refrsh-token',
        'controller' => 'AuthController@refreshToken',
        'desc' => 'to get a new access_token',
        'group' => 'Authentication',
        'params' => [
            'json' => [

                'email' => 'Optional: Email address',
                'password' => 'Optional: Phone number'

            ]
        ]
    ]);







});


// Protected Routes (JWT Required)
// We group these with the 'auth' middleware to protect them all at once
$router->group(['middleware' => 'auth'], function ($router) {



    // Post management
    $router->add([
        'method' => 'POST',
        'url' => 'post/create',
        'controller' => 'PostController@createPost',
        'desc' => 'create a post',
        'group' => 'Post Management',
        'params' => [
            'json' => [
                'title' => 'string title required',
                'slug' => 'string URL-friendly slug (string, required)',
                'excerpt' => 'string Short summary of the post (string, optional)',
                'body' => 'string Full post content (string, required)',
                'thumbnail_id' => 'int Media ID for thumbnail image db(media.id)',
                'allow_comments' => 'int default(1)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);


    $router->add([
        'method' => 'GET',
        'url' => 'post/viewpost',
        'controller' => 'PostController@viewPost',
        'desc' => 'get post detail based on slug',
        'group' => 'Post Management',
        'params' => [
            'get' => [

                'slug' => 'URL-friendly slug (string, required)'
            ],

            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);


    $router->add([
        'method' => 'GET',
        'url' => 'post/getposts',
        'controller' => 'PostController@listPosts',
        'desc' => 'get post details by pagination',
        'group' => 'Post Management',
        'params' => [
            'get' => [

                'page' => 'page no defult 0 (int, required)',
                'limit' => 'limit max 20 (int, required)'
            ],

            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);




    $router->add([
        'method' => 'GET',
        'url' => 'post/getpost-counts',
        'controller' => 'PostController@getCounts',
        'desc' => 'get total post counts',
        'group' => 'Post Management',
        'params' => [
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);



    $router->add([
        'method' => 'PUT',
        'url' => '/post/update',
        'controller' => 'PostController@updatePost',
        'desc' => 'Update an existing post',
        'group' => 'Post Management',
        'params' => [
            'json' => [
                'id' => 'int Post ID (integer, required) db(posts.id)',
                'title' => 'string Post title (string, optional) db(posts.title)',
                'slug' => 'string URL-friendly slug (string, optional)db(posts.slug)',
                'excerpt' => 'string Short summary of the post (string, optional)',
                'body' => 'string Full post content (string, optional)',
                'thumbnail_id' => 'int Media ID for thumbnail image (integer, optional) db(media.id)',
                'allow_comments' => 'int Allow comments on the post (boolean/int, optional) default(1)'
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token'
            ]
        ]
    ]);



    $router->add([
        'method' => 'GET',
        'url' => '/post/get',
        'controller' => 'PostController@getPost',
        'desc' => 'Fetch a post by its ID',
        'group' => 'Post Management',
        'params' => [
            'get' => [
                'id' => 'int Post ID (integer, required) db(posts.id)',
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token',
            ]
        ]
    ]);








    // Media management  ---------------------------------------------
    $router->add([
        'method' => 'POST',
        'url' => 'media/upload',
        'controller' => 'MediaController@upload2',
        'desc' => 'Upload a new file to the server and register it in the media library.',
        'group' => 'Media Management',
        'params' => [
            'json' => [
                'file' => 'The file to upload (multipart/form-data, required)',
                'alt_text' => 'Alternative text for the image (string, optional)',
                'caption' => 'Caption for the media file (string, optional)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]


        ]
    ]);

    $router->add([
        'method' => 'GET',
        'url' => 'media',
        'controller' => 'MediaController@listMedia',
        'desc' => 'Get a paginated list of media files (optionally filtered by type).',
        'group' => 'Media Management',
        'params' => [
            'get' => [
                'page' => 'int  Page number for pagination (integer, optional, default: 1)',
                'limit' => 'int Number of items per page (integer, optional, default: 50)',
                'type' => 'string type of the media enum(image|audio|video|document|all)',
                'is_trashed' => 'int Set to true to fetch soft-deleted media files defult(0)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);

    $router->add([
        'method' => 'GET',
        'url' => 'media/search',
        'controller' => 'MediaController@searchMedia',
        'desc' => 'Search media files by filename or alt text.',
        'group' => 'Media Management',
        'params' => [
            'get' => [
                'q' => 'Search query (string, required)',
                'limit' => 'Number of results to return (integer, optional, default: 50)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);

    $router->add([
        'method' => 'PUT',
        'url' => 'media/',
        'controller' => 'MediaController@updateMedia',
        'desc' => 'Update media metadata (alt text, caption, filename).',
        'group' => 'Media Management',
        'params' => [
            'json' => [
                'id' => 'Media ID (integer, required in payload)',
                'alt_text' => 'Alternative text (string, optional)',
                'caption' => 'Media caption (string, optional)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);


    $router->add([
        'method' => 'GET',
        'url' => 'media/stats',
        'controller' => 'MediaController@getStats',
        'desc' => 'Get total count and sizes of media files including trash',
        'group' => 'Media Management',
    ]);


    $router->add([
        'method' => 'POST',
        'url' => 'media/trash',
        'controller' => 'MediaController@softDelete',
        'desc' => 'Get total count and sizes of media files including trash',
        'group' => 'Media Management',
        'params' => [
            'json' => [
                'id' => 'int Media ID db(media.id)',
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);


    $router->add([
        'method' => 'DELETE',
        'url' => 'media/',
        'controller' => 'MediaController@hardDelete',
        'desc' => 'Get total count and sizes of media files including trash',
        'group' => 'Media Management',
        'params' => [
            'json' => [
                'id' => 'int Media ID db(media.id)',
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>',
            ]
        ]
    ]);


    $router->add([
        'method' => 'PUT',
        'url' => 'media/restore',
        'controller' => 'MediaController@restore',
        'desc' => 'Restore a soft-deleted media file',
        'group' => 'Media Management',
        'params' => [
            'json' => [
                'id' => 'The ID of the media file to restore (integer, required)'
            ],
            'headers' => [
                'Authorization' => 'Bearer <JWT token>'
            ]
        ]
    ]);





    // Category and Tags ---------------------------------------------------


    $router->add([
        'method' => 'GET',
        'url' => 'categories',
        'controller' => 'TaxonomyController@getCategories',
        'desc' => 'Fetch hierarchical category tree',
        'group' => 'Taxonomy',
        'params' => [

            'headers' => [
                'Authorization' => 'string defualt(Bearer xxx)',
            ]
        ]
    ]);



    $router->add([
        'method' => 'GET',
        'url' => 'tags',
        'controller' => 'TaxonomyController@getTags',
        'desc' => 'Fetch all tags',
        'group' => 'Taxonomy',
    ]);


    $router->add([
        'method' => 'GET',
        'url' => 'categories/posts',
        'controller' => 'TaxonomyController@getCategoryPosts',
        'desc' => 'Fetch published posts for a category (by slug)',
        'group' => 'Taxonomy',
        'params' => [
            'get' => [
                'slug' => 'Category slug (required)',
                'page' => 'Page (optional, default 1)',
                'limit' => 'Limit (optional, default 20)',
            ],
        ],
    ]);



    $router->add([
        'method' => 'POST',
        'url' => 'categories',
        'controller' => 'TaxonomyController@createCategory',
        'desc' => 'Create a new category',
        'group' => 'Taxonomy (Admin)',
        'params' => [
            'json' => [
                'name' => 'string this is name of category',
                'parent_id' => 'int parent id of the category db(categories.id)',
                'slug' => 'string slug',
                'description' => 'string description',
                'thumbnail_id' => 'int thumbnail id form the media table db(media.id)',
                'sort_order' => 'int sorting order default(0)',
                'is_active' => 'int status of category enum(1|0)',
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token default(Bearer blah_blah)',
            ],
        ],
    ]);


    $router->add([
        'method' => 'PUT',
        'url' => 'categories',
        'controller' => 'TaxonomyController@updateCategory',
        'desc' => 'Update an existing category (id in JSON)',
        'group' => 'Taxonomy (Admin)',
        'params' => [
            'json' => [
                'id' => 'int id of the category db(categoires.id)',
                'parent_id' => 'int db(categoires.id)',
                'name' => 'string name ',
                'slug' => 'string slug',
                'description' => 'string description',
                'thumbnail_id' => 'int media id db(media.id)',
                'sort_order' => 'int default(0)',
                'is_active' => 'int enum(1|0)',
            ],
            'headers' => [
                'Authorization' => 'string defualt(Bearer xxx)',
            ],
        ],
    ]);



    $router->add([
        'method' => 'DELETE',
        'url' => 'categories',
        'controller' => 'TaxonomyController@deleteCategory',
        'desc' => 'Delete a category (id in JSON)',
        'group' => 'Taxonomy (Admin)',
        'params' => [
            'json' => [
                'id' => 'int db(categories.id)',
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token',
            ],
        ],
    ]);


    $router->add([
        'method' => 'POST',
        'url' => 'tags',
        'controller' => 'TaxonomyController@createTag',
        'desc' => 'Create a new tag',
        'group' => 'Taxonomy (Admin)',
        'params' => [
            'json' => [
                'name' => 'Tag name (required)',
                'slug' => 'Slug (optional)',
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token',
            ],
        ],
    ]);


    $router->add([
        'method' => 'DELETE',
        'url' => 'tags',
        'controller' => 'TaxonomyController@deleteTag',
        'desc' => 'Delete a tag (id in JSON)',
        'group' => 'Taxonomy (Admin)',
        'params' => [
            'json' => [
                'id' => 'Tag id (required)',
            ],
            'headers' => [
                'Authorization' => 'Bearer JWT token',
            ],
        ],
    ]);


});





// // Protected Routes (JWT Required) for admin routes
// $router->group(['middleware' => 'adminAuth'], function ($router) {

// });

