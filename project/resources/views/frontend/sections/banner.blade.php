{{-- Banner: classy hero. Texts and the picture still come from the admin panel (Frontend > Sections > Banner). --}}
@php
    // "Pay. Get paid. Transfer. Escrow" (the admin's sub heading) becomes small elegant chips
    $bannerPoints = array_values(array_filter(array_map('trim', preg_split('/[.|\x{2022}]+/u', (string) @$section->content->sub_heading))));
@endphp
<!-- Banner -->
<section class="banner-section">
    <div class="container">
        <div class="banner__wrapper">
            <div class="banner__wrapper-content">
                <span class="banner-eyebrow">{{__(@$section->content->title)}}</span>

                <h1 class="title">@lang(@$section->content->heading)</h1>

                

                <a class="banner-store" href="https://play.google.com/store/apps/details?id=com.droppcash.app" target="_blank" rel="noopener">
                    <img src="{{ asset('assets/images/get-it-on-google-play-2016-logo.png') }}" alt="@lang('Get it on Google Play')">
                </a>
            </div>

            <div class="banner__wrapper-thumb">
                <div class="banner-frame">
                    <img src="{{getPhoto(@$section->content->image)}}" alt="{{ strip_tags(__(@$section->content->heading)) }}">
                </div>
            </div>
        </div>
    </div>
</section>
<!-- Banner -->
