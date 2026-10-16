@php
$policies = App\Models\SiteContent::where('slug','policies')->first();
@endphp
<!-- Footer -->
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-REHC2YDNQC"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-REHC2YDNQC');
</script>
<footer class="position-relative overflow-hidden">
<div class="footer-top bg--section">
   <div class="container">
       <div class="footer-wrapper">
           <div class="footer-widget">
               <h5 class="footer-title">@lang('Menu')</h5>
               <ul>
                   @foreach (json_decode($gs->menu) as $item)
                   <li>
                      <a target="{{$item->target == 'self' ? '':'_blank'}}" href="{{url($item->href)}}">{{__($item->title)}}</a>
                   </li>
               @endforeach
                 
               </ul>
           </div>
           <div class="footer-widget">
               <h5 class="footer-title">@lang('Get Started')</h5>
               <ul>
                   <li>
                       <a href="{{route('user.register')}}">@lang('Register as a User')</a>
                   </li>
                   <li>
                       <a href="{{route('user.login')}}">@lang('Login as a User')</a>
                   </li>
                   <li>
                       <a href="{{route('merchant.register')}}">@lang('Register as a Merchant')</a>
                   </li>
                   <li>
                       <a href="{{route('merchant.login')}}">@lang('Login as a Merchant')</a>
                   </li>
                   <li>
                       <a href="{{route('agent.register')}}">@lang('Register as an Agent')</a>
                   </li>
                   <li>
                       <a href="{{route('agent.login')}}">@lang('Login as an Agent')</a>
                   </li>
                  
               </ul>
           </div>
           
           <div class="footer-widget">
                    <h5 class="footer-title">@lang('Solutions')</h5>
                  <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Transfer Money')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                    <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Make Payment')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                  <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Send Invoice')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                  <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Cash Out/Cash In')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                  <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Create Escrow')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                  <ul>
                        <li>
                          <a href="{{route('user.login')}}">@lang('Exchange Currency')</a>
                        </li>
                        @foreach (DB::table('pages')->where('lang',app()->currentLocale())->get() as $item)
                            @if ($item->slug != 'about')
                                <li>
                                    <a href="{{route('pages',[$item->id,$item->slug])}}">@lang($item->title)</a>
                                </li>
                      
                            @endif
                        @endforeach
                    </ul>
                  
                  
                </div>
            </div>
        </div>
    </div>
    <div class="footer-bottom bg--body py-4">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-center justify-content-sm-between bottom-menu-wrapper">
                <div class="footer-logo">
                    <a href="{{url('/')}}">
                        <img src="{{getPhoto($gs->header_logo)}}" alt="logo">
                    </a>
                </div>
                <div class="copyright">
                    &copy; @lang('2026. Powered by') <a href="#" class="text--base">DroppCash Technology - RC8569798</a>
                </div>
                <ul class="bottom-menu">
                    @foreach ($policies->sub_content as $key=> $item)
                        @if (app()->currentLocale() == $item->lang)
                            <li>
                                <a href="{{route('terms.details',[$key,Str::slug($item->title)])}}">{{__($item->title)}}</a>
                            </li>
                        @endif
                    @endforeach
                   
                </ul>
            </div>
        </div>
    </div>
    <!--Start of Tawk.to Script-->
<script type="text/javascript">
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/64db4f9194cf5d49dc6a7e4d/1h7satl80';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
<!--End of Tawk.to Script-->
</footer>
<!-- Footer -->