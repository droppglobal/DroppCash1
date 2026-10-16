 <!-- Banner -->
 <section class="banner-section">
    <div class="container">
        <div class="banner__wrapper">
            <div class="banner__wrapper-content">
                <h1 class="subtitle text--base">{{__(@$section->content->title)}}</h1>


                <h3 class="title">
                    @lang(@$section->content->heading)
                </h3>
                
                

                    <div class="footer-logo">
                    <a href="{{url('https://play.google.com/store/apps/details?id=com.droppcash.app')}}"> <img src="https://droppcash.app/assets/images/get-it-on-google-play-2016-logo.png" alt="logo">
                    </a>
                </div>

                </div>
            </div>
            <div class="banner__wrapper-thumb">
                <img src="{{getPhoto(@$section->content->image)}}"/>
            </div>
        </div>
    </div>
    
</section>
<!-- Banner -->