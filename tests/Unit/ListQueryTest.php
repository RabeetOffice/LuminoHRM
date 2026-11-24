<?php

use App\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

function req(string $query): Request
{
    return Request::create('/things?'.$query);
}

it('escapes like wildcards and lowercases', function () {
    expect(ListQuery::escapeLike('50%_Off!'))->toBe('50!%!_off!!')
        ->and(ListQuery::escapeLike('ÀBC'))->toBe('àbc')
        ->and(ListQuery::escapeLike(''))->toBe('');
});

it('limits per_page to 10, 25 or 50 and defaults to 10', function (string $query, int $expected) {
    expect(ListQuery::perPage(req($query)))->toBe($expected);
})->with([
    ['', 10], ['per_page=25', 25], ['per_page=50', 50], ['per_page=10', 10],
    ['per_page=7', 10], ['per_page=abc', 10], ['per_page=-25', 10], ['per_page[]=25', 10], ['per_page=25.5', 10],
]);

it('reads a page number and never fails on junk', function (string $query, int $expected) {
    expect(ListQuery::page(req($query), 'page'))->toBe($expected);
})->with([
    ['', 1], ['page=3', 3], ['page=0', 1], ['page=-2', 1], ['page=abc', 1], ['page[]=2', 1],
    ['page=99999999999999999999999', 1_000_000_000],
]);

it('reads the page under its own parameter name', function () {
    expect(ListQuery::page(req('dept_page=4&page=2'), 'dept_page'))->toBe(4);
});

it('trims a search term and caps it at 100 characters', function () {
    expect(ListQuery::stringParam(req('search=%20%20abc%20'), 'search'))->toBe('abc')
        ->and(mb_strlen(ListQuery::stringParam(req('search='.str_repeat('é', 300)), 'search')))->toBe(100)
        ->and(ListQuery::stringParam(req('search[]=x'), 'search'))->toBe('')
        ->and(ListQuery::stringParam(req(''), 'search'))->toBe('');
});

it('redirects to the real last page when a page is past the end', function () {
    $request = req('page=5&per_page=25');
    $paginator = new LengthAwarePaginator([], 12, 10, 5);

    $redirect = ListQuery::clampRedirect($request, ['page' => $paginator]);

    expect($redirect)->not->toBeNull()
        ->and($redirect->getTargetUrl())->toBe('http://localhost/things?page=2&per_page=25');
});

it('drops the page parameter when the real last page is the first', function () {
    $request = req('page=3&per_page=25');
    $paginator = new LengthAwarePaginator([], 0, 10, 3);

    expect(ListQuery::clampRedirect($request, ['page' => $paginator])->getTargetUrl())->toBe('http://localhost/things?per_page=25');
});

it('drops the question mark when nothing is left', function () {
    $paginator = new LengthAwarePaginator([], 0, 10, 3);

    expect(ListQuery::clampRedirect(req('page=3'), ['page' => $paginator])->getTargetUrl())->toBe('http://localhost/things');
});

it('clamps only the paginator that is past the end', function () {
    $request = req('dept_page=2&desig_page=9');
    $fine = new LengthAwarePaginator([], 30, 10, 2);
    $past = new LengthAwarePaginator([], 12, 10, 9);

    $redirect = ListQuery::clampRedirect($request, ['dept_page' => $fine, 'desig_page' => $past]);

    expect($redirect->getTargetUrl())->toBe('http://localhost/things?dept_page=2&desig_page=2');
});

it('returns null when every page exists', function () {
    $paginator = new LengthAwarePaginator([], 30, 10, 3);

    expect(ListQuery::clampRedirect(req('page=3'), ['page' => $paginator]))->toBeNull();
});
