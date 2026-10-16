@extends('layouts.frontend')

@section('title')
    @lang(@$page->title ?? 'About')
@endsection

@section('content')
   @include('frontend.sections.about',['section'=>$section])
   @if ($page)
   <section class="ctas-section pt-50 pb-100 position-relative overflow-hidden">
    <div class="container">
        <div class="section-title text-center">
            <h2 class="title">@lang('More About Us')</h2>
        </div>
        <div class="blog__details">
            <p>
                @php
                    echo $page->details;
                @endphp
            </p>          
        </div>
    </div>
    
   </section>
   @endif
@endsection