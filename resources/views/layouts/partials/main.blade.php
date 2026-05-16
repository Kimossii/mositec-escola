<!--begin::Main-->
<div class="app-main flex-column flex-row-fluid" id="kt_app_main">

    <!--begin::Content wrapper-->
    <div class="d-flex flex-column flex-column-fluid">

        <!--begin::Toolbar-->
        @include('layouts.partials.toolbar')
        <!--end::Toolbar-->

        <!--begin::Content-->
        <div class="app-content flex-column-fluid" id="kt_app_content">

            <!--begin::Container-->
            <div class="container-xxl">

                @include('dashboard.sections.dashboard-content')

            </div>
            <!--end::Container-->

        </div>
        <!--end::Content-->

    </div>
    <!--end::Content wrapper-->

    @include('layouts.partials.footer')

</div>
<!--end::Main-->
