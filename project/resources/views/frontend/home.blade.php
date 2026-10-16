@extends('layouts.frontend')

@section('title')
    @lang('The money app for Africa and beyond')
@endsection

@section('content')
    @foreach ($sections as $section)

        @if($section->status == 1)
            @includeif('frontend.sections.'.$section->slug,['section' => $section])
        @endif
    @endforeach
@endsection