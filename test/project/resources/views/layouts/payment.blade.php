<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @include('other.seo')
    <title>{{__($gs->title)}}-@yield('title')</title>

    <link rel="stylesheet" href="{{asset('assets/frontend')}}/css/bootstrap.min.css" />
    <link rel="stylesheet" href="{{asset('assets/frontend')}}/css/all.min.css" />
    <link href="{{asset('assets/frontend/css/main.php')}}?color={{$gs->theme_color}}" rel="stylesheet" />
    <link rel="shortcut icon" href="{{getPhoto($gs->favicon)}}">
    <style>
        #cardNumber,
#securityCode,
#expirationDate {
    display: block;
    width: 94%;
    height:40px;
    margin-left:10px;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    color: #212529;
    background-color: #fff;
    background-clip: padding-box;
    border: 1px solid #ced4da;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    border-radius: 0.25rem;
    transition: border-color .15s ease-in-out,box-shadow .15s ease-in-out;
}

#cardNumber,
#securityCode,
#expirationDate {
  margin-bottom: 18px;
}
    </style>
</head>
<body>

@yield('content')
<div class="fixed--anime">
    <span class="banner-elem elem1">&nbsp;</span>
    <span class="banner-elem elem2">&nbsp;</span>
    <span class="banner-elem elem3">&nbsp;</span>
    <span class="banner-elem elem4">&nbsp;</span>
    <span class="banner-elem elem5">&nbsp;</span>
    <span class="banner-elem elem6">&nbsp;</span>
    <span class="banner-elem elem7">&nbsp;</span>
    <span class="banner-elem elem8">&nbsp;</span>
    <span class="banner-elem elem9">&nbsp;</span>

</div>

<script src="{{asset('assets/frontend')}}/js/jquery-3.6.0.min.js"></script>
<script src="{{asset('assets/frontend')}}/js/bootstrap.min.js"></script>
<script src="{{asset('assets/frontend')}}/js/viewport.jquery.js"></script>
<script src="{{asset('assets/frontend')}}/js/odometer.min.js"></script>
<script src="{{asset('assets/frontend')}}/js//owl.min.js"></script>
<script src="{{asset('assets/frontend')}}/js/main.js"></script>
@include('notify.alert')
@stack('script')
</body>

</html>
