<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title>{{__($gs->title)}}-@yield('title')</title>
    <!-- CSS files -->
    <link rel="shortcut icon" href="{{getPhoto($gs->favicon)}}">
    <link rel="stylesheet" href="{{asset('assets/admin/css/font-awsome.min.css')}}">

    <link href="{{asset('assets/user/')}}/css/tabler.min.css" rel="stylesheet"/>
    <link href="{{asset('assets/user/')}}/css/tabler-flags.min.css" rel="stylesheet"/>
    <link href="{{asset('assets/user/')}}/css/tabler-payments.min.css" rel="stylesheet"/>
    <link href="{{asset('assets/user/')}}/css/tabler-vendors.min.css" rel="stylesheet"/>
    <link href="{{asset('assets/user/')}}/css/demo.min.css" rel="stylesheet"/>
    <link href="{{asset('assets/user/')}}/css/custom.css" rel="stylesheet"/>
 
    <link rel="stylesheet" href="{{ asset('assets/merchant/css/tagify.css') }}">
    <link rel="stylesheet" href="{{asset('assets/merchant/css/summernote.css')}}">
    <link rel="stylesheet" href="{{asset('assets/merchant/css/bootstrap-iconpicker.min.css')}}">
    <link rel="stylesheet" href="{{asset('assets/merchant/css/colorpicker.css')}}">

    
    @stack('style')
 
  </head>
  
  <body>
    <div class="wrapper">
          <div class="page-wrapper">
     
            <div class="page-body">
               @yield('content')
            </div>
          </div>
      </div>


      <script src="{{asset('assets/admin/js/jquery.min.js')}}"></script>
      <script src="{{asset('assets/user/')}}/libs/apexcharts/dist/apexcharts.min.js"></script>
      <!-- Tabler Core -->
      <script src="{{asset('assets/user/')}}/js/tabler.min.js"></script>
      <script src="{{asset('assets/user/')}}/js/demo.min.js"></script>


      <script src="{{asset('assets/merchant/js/jquery.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/popper.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/bootstrap.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/jquery-ui.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/nicescroll.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/summernote.js')}}"></script>
    <script src="{{asset('assets/merchant/js/select2.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/tagify.js') }}"></script>
    <script src="{{asset('assets/merchant/js/sortable.js') }}"></script>
    <script src="{{asset('assets/merchant/js/moment-a.js')}}"></script>
    <script src="{{asset('assets/merchant/js/stisla.js')}}"></script>
    <script src="{{asset('assets/merchant/js/bootstrap-iconpicker.bundle.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/colorpicker.js')}}"></script>
    <script src="{{asset('assets/merchant/js/jquery.uploadpreview.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/chart.min.js')}}"></script>
    <script src="{{asset('assets/merchant/js/scripts.js')}}"></script>
    <script src="{{asset('assets/merchant/js/custom.js')}}"></script>


      @if(request()->routeIs('user.transfer.money') || request()->routeIs('user.request.money') || request()->routeIs('user.make.payment') || request()->routeIs('user.escrow.create') || request()->routeIs('user.cashout.form'))

      <div class="modal fade" id="cameraModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">     
                <div class="modal-body text-center">
                    <video id="preview" class="p-1 border" style="width:400px;"></video>
                </div>
                <div class="modal-footer justify-content-center">
                  <button type="button" class="btn btn-dark" data-bs-dismiss="modal">@lang('close')</button>
                </div>
          </div>
        </div>
      </div>

      <script src="{{asset('assets/user/')}}/js/instascan.min.js"></script>
      <script>
        'use strict';
        $('.scan').click(function(){
            var scanner = new Instascan.Scanner({ video: document.getElementById('preview'), scanPeriod: 5, mirror: false });
            scanner.addListener('scan',function(content){
                var route = '{{url('qr-code-scan')}}'+'/'+content
                $.get(route, function( data ) {
                    if(data.error){
                        alert(data.error)
                    } else {
                        $("#receiver").val(data); 
                        $("#receiver").focusout()
                    }
                    $('#cameraModal').modal('hide')
                });
            });
            Instascan.Camera.getCameras().then(function (cameras){
                if(cameras.length>0){
                    $('#cameraModal').modal('show')
                        scanner.start(cameras[1]);
                } else{
                    alert('No cameras found.');
                }
            }).catch(function(e){
                alert('No cameras found.');
            });
        });

      </script>

      @endif

      <script>
        'use strict';
        $('.reason').on('click',function(){
          $('#modal-reason').find('.reason-text').val($(this).data('reason'))
          $('#modal-reason').modal('show')
        })
       
        var theme = localStorage.getItem("tablerTheme");
        var element = $(document).find('.page-title');
        if(theme == 'dark'){
          element.css('color','white');
          $(document).find('.kyc__text').removeClass('text-dark').addClass('text-light')
          $(document).find('.file-type').css('color','#67737e')
          $(document).find('.language-bar').css('color','#f7fafd')
          $(document).find('td').addClass('res')
        }else{
          element.css('color','black');
        }
      </script>
 <script>

var form_error   = "{{ __('Please fill all the required fields') }}";
var mainurl = "{{ url('/') }}";
var lang  = {
      'new': '{{ __('ADD NEW') }}',
      'edit': '{{ __('EDIT') }}',
      'details': '{{ __('DETAILS') }}',
      'update': '{{ __('Status Updated Successfully.') }}',
      'sss': '{{ __('Success !') }}',
      'active': '{{ __('Activated') }}',
      'deactive': '{{ __('Deactivated') }}',
      'loading': '{{ __('Please wait Data Processing...') }}',
      'submit': '{{ __('Submit') }}',
      'enter_name': '{{ __('Enter Name') }}',
      'enter_price': '{{ __('Enter Price') }}',
      'per_day': '{{ __('Per Day') }}',
      'per_month': '{{ __('Per Month') }}',
      'per_year': '{{ __('Per Year') }}',
      'one_time': '{{ __('One Time') }}',
      'enter_title': '{{ __('Enter Title') }}',
      'enter_content': '{{ __('Enter Content') }}',
      'extra_price_name' : '{{__('Enter Name')}}',
      'extra_price' : '{{__('Enter Price')}}',
      'policy_title' : '{{__('Enter Title')}}',
      'policy_content' : '{{__('Enter Content')}}',
  };

</script>


<script>
$(function(){
  'use strict'
  $('.reason').on('click',function(){
    $('#modal-reason').find('.reason-text').val($(this).data('reason'))
    $('#modal-reason').modal('show')
  })

})

$('.summernote').summernote()
</script>
      @include('notify.alert')
      @stack('script')

</body>
</html>
