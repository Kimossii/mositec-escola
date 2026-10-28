<!--begin::Sidebar-->
<div id="kt_app_sidebar" class="app-sidebar  flex-column "
     data-kt-drawer="true" data-kt-drawer-name="app-sidebar" data-kt-drawer-activate="{default: true, lg: false}" data-kt-drawer-overlay="true" data-kt-drawer-width="225px" data-kt-drawer-direction="start" data-kt-drawer-toggle="#kt_app_sidebar_mobile_toggle"
     >


<!--begin::Logo-->
<div class="app-sidebar-logo px-6" id="kt_app_sidebar_logo">
    <!--begin::Logo image-->
    <a href="{{ url('/') }}">
        <img alt="MosiTec" src="{{ asset('themes/metronic/assets/media/logos/mosi-logo-branco.png') }}" class="h-25px app-sidebar-logo-default"/>
        <img alt="MosiTec" src="{{ asset('themes/metronic/assets/media/logos/mosi-marca.png') }}" class="h-20px app-sidebar-logo-minimize"/>
    </a>
    <!--end::Logo image-->

            <!--begin::Sidebar toggle-->
        <!--begin::Minimized sidebar setup:
            if (isset($_COOKIE["sidebar_minimize_state"]) && $_COOKIE["sidebar_minimize_state"] === "on") {
                1. "src/js/layout/sidebar.js" adds "sidebar_minimize_state" cookie value to save the sidebar minimize state.
                2. Set data-kt-app-sidebar-minimize="on" attribute for body tag.
                3. Set data-kt-toggle-state="active" attribute to the toggle element with "kt_app_sidebar_toggle" id.
                4. Add "active" class to to sidebar toggle element with "kt_app_sidebar_toggle" id.
            }
        -->
        <div
            id="kt_app_sidebar_toggle"
            class="app-sidebar-toggle btn btn-icon btn-shadow btn-sm btn-color-muted btn-active-color-primary body-bg h-30px w-30px position-absolute top-50 start-100 translate-middle rotate {{ $sidebarMinimizado ? 'active' : '' }}"
            data-kt-toggle="true"
            @if ($sidebarMinimizado) data-kt-toggle-state="active" @endif
            data-kt-toggle-target="body"
            data-kt-toggle-name="app-sidebar-minimize"
            >

            <i class="ki-duotone ki-double-left fs-2 rotate-180"><span class="path1"></span><span class="path2"></span></i>        </div>
        <!--end::Sidebar toggle-->
    </div>
<!--end::Logo-->
<!--begin::sidebar menu-->
<div class="app-sidebar-menu overflow-hidden flex-column-fluid">
    <!--begin::Menu wrapper — montado via Vue (SidebarMenuWrapper.vue) -->
    <div id="sidebar-menu-wrapper-mount"></div>
    <!--end::Menu wrapper-->
</div>
<!--end::sidebar menu-->
<!--begin::Footer-->
<div class="app-sidebar-footer flex-column-auto pt-2 pb-6 px-6" id="kt_app_sidebar_footer">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button
            type="submit"
            class="btn btn-flex flex-center btn-custom btn-danger overflow-hidden text-nowrap px-0 h-40px w-100"
            data-bs-toggle="tooltip"
            data-bs-trigger="hover"
            title="Terminar a sessão">

            <span class="btn-label">
                Terminar Sessão
            </span>

            <i class="ki-duotone ki-exit-right btn-icon fs-2 m-0"><span class="path1"></span><span class="path2"></span></i>
        </button>
    </form>
</div>
<!--end::Footer-->    </div>
<!--end::Sidebar-->
