<script setup>
import { onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePageScripts } from '@/composables/usePageScripts';

const { loadAll } = usePageScripts([
    '/themes/metronic/assets/plugins/custom/fullcalendar/fullcalendar.bundle.js',
    'https://cdn.amcharts.com/lib/5/index.js',
    'https://cdn.amcharts.com/lib/5/xy.js',
    'https://cdn.amcharts.com/lib/5/percent.js',
    'https://cdn.amcharts.com/lib/5/radar.js',
    'https://cdn.amcharts.com/lib/5/themes/Animated.js',
    'https://cdn.amcharts.com/lib/5/map.js',
    'https://cdn.amcharts.com/lib/5/geodata/worldLow.js',
    'https://cdn.amcharts.com/lib/5/geodata/continentsLow.js',
    'https://cdn.amcharts.com/lib/5/geodata/usaLow.js',
    'https://cdn.amcharts.com/lib/5/geodata/worldTimeZonesLow.js',
    'https://cdn.amcharts.com/lib/5/geodata/worldTimeZoneAreasLow.js',
    '/themes/metronic/assets/plugins/custom/datatables/datatables.bundle.js',
    '/themes/metronic/assets/js/widgets.bundle.js',
    '/themes/metronic/assets/js/components/custom/widgets.js',
    '/themes/metronic/assets/js/components/custom/apps/chat/chat.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/upgrade-plan.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/create-app.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/new-target.js',
    '/themes/metronic/assets/js/components/custom/utilities/modals/users-search.js',
    '/themes/metronic/assets/js/pages/dashboard.js',
]);

onMounted(() => {
    loadAll();
});
defineOptions({ layout: AppLayout })
</script>

<template>
  <!--begin::Main-->
  <div class="app-main flex-column flex-row-fluid" id="kt_app_main">
    <div class="d-flex flex-column flex-column-fluid">

      <!--begin::Toolbar-->
      <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
        <div id="kt_app_toolbar_container" class="app-container container-fluid d-flex flex-stack">
          <div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
            <h1 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">
              Multipurpose
            </h1>
            <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
              <li class="breadcrumb-item text-muted">
                <a href="#" class="text-muted text-hover-primary">Home</a>
              </li>
              <li class="breadcrumb-item">
                <span class="bullet bg-gray-400 w-5px h-2px"></span>
              </li>
              <li class="breadcrumb-item text-muted">Dashboards</li>
            </ul>
          </div>
          <div class="d-flex align-items-center gap-2 gap-lg-3">
            <a href="#" class="btn btn-sm fw-bold bg-body btn-color-gray-700 btn-active-color-primary" data-bs-toggle="modal" data-bs-target="#kt_modal_create_app">Rollover</a>
            <a href="#" class="btn btn-sm fw-bold btn-primary" data-bs-toggle="modal" data-bs-target="#kt_modal_new_target">Add Target</a>
          </div>
        </div>
      </div>
      <!--end::Toolbar-->

      <!--begin::Content-->
      <div class="app-content flex-column-fluid" id="kt_app_content">
        <div class="app-container container-fluid" id="kt_app_content_container">

          <!--begin::Top Widgets-->
          <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
            <div class="col-md-6 col-lg-6 col-xl-6 col-xxl-3 mb-md-5 mb-xl-10">
              <div class="card card-flush bgi-no-repeat bgi-size-contain bgi-position-x-end h-md-50 mb-5 mb-xl-10"
                style="background-color: #F1416C;background-image:url('/themes/metronic/assets/media/patterns/vector-1.png')">
                <div class="card-header pt-5">
                  <div class="card-title d-flex flex-column">
                    <span class="fs-2hx fw-bold text-white me-2 lh-1 ls-n2">69</span>
                    <span class="text-white opacity-75 pt-1 fw-semibold fs-6">Active Projects</span>
                  </div>
                </div>
                <div class="card-body d-flex align-items-end pt-0">
                  <div class="d-flex align-items-center flex-column mt-3 w-100">
                    <div class="d-flex justify-content-between fw-bold fs-6 text-white opacity-75 w-100 mt-auto mb-2">
                      <span>43 Pending</span><span>72%</span>
                    </div>
                    <div class="h-8px mx-3 w-100 bg-white bg-opacity-50 rounded">
                      <div class="bg-white rounded h-8px" role="progressbar" style="width: 72%;"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card card-flush h-md-50 mb-5 mb-xl-10">
                <div class="card-header pt-5">
                  <div class="card-title d-flex flex-column">
                    <span class="fs-2hx fw-bold text-dark me-2 lh-1 ls-n2">357</span>
                    <span class="text-gray-400 pt-1 fw-semibold fs-6">Professionals</span>
                  </div>
                </div>
                <div class="card-body d-flex flex-column justify-content-end pe-0">
                  <span class="fs-6 fw-bolder text-gray-800 d-block mb-2">Today's Heroes</span>
                  <div class="symbol-group symbol-hover flex-nowrap">
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Alan Warden">
                      <span class="symbol-label bg-warning text-inverse-warning fw-bold">A</span>
                    </div>
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Michael Eberon">
                      <img alt="Pic" src="/themes/metronic/assets/media/avatars/300-11.jpg" />
                    </div>
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Susan Redwood">
                      <span class="symbol-label bg-primary text-inverse-primary fw-bold">S</span>
                    </div>
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Melody Macy">
                      <img alt="Pic" src="/themes/metronic/assets/media/avatars/300-2.jpg" />
                    </div>
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Perry Matthew">
                      <span class="symbol-label bg-danger text-inverse-danger fw-bold">P</span>
                    </div>
                    <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Barry Walter">
                      <img alt="Pic" src="/themes/metronic/assets/media/avatars/300-12.jpg" />
                    </div>
                    <a href="#" class="symbol symbol-35px symbol-circle" data-bs-toggle="modal" data-bs-target="#kt_modal_view_users">
                      <span class="symbol-label bg-dark text-gray-300 fs-8 fw-bold">+42</span>
                    </a>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6 col-lg-6 col-xl-6 col-xxl-3 mb-md-5 mb-xl-10">
              <div class="card card-flush h-md-50 mb-5 mb-xl-10">
                <div class="card-header pt-5">
                  <div class="card-title d-flex flex-column">
                    <div class="d-flex align-items-center">
                      <span class="fs-4 fw-semibold text-gray-400 me-1 align-self-start">$</span>
                      <span class="fs-2hx fw-bold text-dark me-2 lh-1 ls-n2">69,700</span>
                      <span class="badge badge-light-success fs-base">
                        <i class="ki-duotone ki-arrow-up fs-5 text-success ms-n1"><span class="path1"></span><span class="path2"></span></i>2.2%
                      </span>
                    </div>
                    <span class="text-gray-400 pt-1 fw-semibold fs-6">Projects Earnings in April</span>
                  </div>
                </div>
                <div class="card-body pt-2 pb-4 d-flex flex-wrap align-items-center">
                  <div class="d-flex flex-center me-5 pt-2">
                    <div id="kt_card_widget_17_chart" style="min-width: 70px; min-height: 70px" data-kt-size="70" data-kt-line="11"></div>
                  </div>
                  <div class="d-flex flex-column content-justify-center flex-row-fluid">
                    <div class="d-flex fw-semibold align-items-center">
                      <div class="bullet w-8px h-3px rounded-2 bg-success me-3"></div>
                      <div class="text-gray-500 flex-grow-1 me-4">Leaf CRM</div>
                      <div class="fw-bolder text-gray-700 text-xxl-end">$7,660</div>
                    </div>
                    <div class="d-flex fw-semibold align-items-center my-3">
                      <div class="bullet w-8px h-3px rounded-2 bg-primary me-3"></div>
                      <div class="text-gray-500 flex-grow-1 me-4">Mivy App</div>
                      <div class="fw-bolder text-gray-700 text-xxl-end">$2,820</div>
                    </div>
                    <div class="d-flex fw-semibold align-items-center">
                      <div class="bullet w-8px h-3px rounded-2 me-3" style="background-color: #E4E6EF"></div>
                      <div class="text-gray-500 flex-grow-1 me-4">Others</div>
                      <div class="fw-bolder text-gray-700 text-xxl-end">$45,257</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-xxl-6">
              <div class="card card-flush h-md-100">
                <div class="card-body d-flex flex-column justify-content-between mt-9 bgi-no-repeat bgi-size-cover bgi-position-x-center pb-0"
                  style="background-position: 100% 50%; background-image:url('/themes/metronic/assets/media/stock/900x600/42.png')">
                  <div class="mb-10">
                    <div class="fs-2hx fw-bold text-gray-800 text-center mb-13">
                      <span class="me-2">Try our all new Environment with<br/>
                        <span class="position-relative d-inline-block text-danger">
                          <a href="#" class="text-danger opacity-75-hover">Pro Plan</a>
                          <span class="position-absolute opacity-15 bottom-0 start-0 border-4 border-danger border-bottom w-100"></span>
                        </span>
                      </span> for Free
                    </div>
                    <div class="text-center">
                      <a href="#" class="btn btn-sm btn-dark fw-bold" data-bs-toggle="modal" data-bs-target="#kt_modal_upgrade_plan">Upgrade Now</a>
                    </div>
                  </div>
                  <img class="mx-auto h-150px h-lg-200px theme-light-show" src="/themes/metronic/assets/media/illustrations/misc/upgrade.svg" alt=""/>
                  <img class="mx-auto h-150px h-lg-200px theme-dark-show" src="/themes/metronic/assets/media/illustrations/misc/upgrade-dark.svg" alt=""/>
                </div>
              </div>
            </div>
          </div>
          <!--end::Top Widgets-->

          <!--begin::Mid Widgets-->
          <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
            <div class="col-xxl-6">
              <div class="card card-flush h-xl-100">
                <div class="card-body py-9">
                  <div class="row gx-9 h-100">
                    <div class="col-sm-6 mb-10 mb-sm-0">
                      <div class="bgi-no-repeat bgi-position-center bgi-size-cover card-rounded min-h-400px min-h-sm-100 h-100"
                        style="background-size: 100% 100%;background-image:url('/themes/metronic/assets/media/stock/600x600/img-65.jpg')">
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="d-flex flex-column h-100">
                        <div class="mb-7">
                          <div class="d-flex flex-stack mb-6">
                            <div class="flex-shrink-0 me-5">
                              <span class="text-gray-400 fs-7 fw-bold me-2 d-block lh-1 pb-1">Featured</span>
                              <span class="text-gray-800 fs-1 fw-bold">9 Degree</span>
                            </div>
                            <span class="badge badge-light-primary flex-shrink-0 align-self-center py-3 px-4 fs-7">In Process</span>
                          </div>
                        </div>
                        <div class="d-flex flex-stack mt-auto bd-highlight">
                          <div class="symbol-group symbol-hover flex-nowrap">
                            <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Melody Macy">
                              <img alt="Pic" src="/themes/metronic/assets/media/avatars/300-2.jpg" />
                            </div>
                            <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Michael Eberon">
                              <img alt="Pic" src="/themes/metronic/assets/media/avatars/300-3.jpg" />
                            </div>
                            <div class="symbol symbol-35px symbol-circle" data-bs-toggle="tooltip" title="Susan Redwood">
                              <span class="symbol-label bg-primary text-inverse-primary fw-bold">S</span>
                            </div>
                          </div>
                          <a href="#" class="d-flex align-items-center text-primary opacity-75-hover fs-6 fw-semibold">
                            View Project
                            <i class="ki-duotone ki-exit-right-corner fs-4 ms-1"><span class="path1"></span><span class="path2"></span></i>
                          </a>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-xl-6">
              <div class="card card-flush overflow-hidden h-lg-100">
                <div class="card-header pt-5">
                  <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-dark">Performance</span>
                    <span class="text-gray-400 mt-1 fw-semibold fs-6">1,046 Inbound Calls today</span>
                  </h3>
                </div>
                <div class="card-body d-flex align-items-end p-0">
                  <div id="kt_charts_widget_36" class="min-h-auto w-100 ps-4 pe-6" style="height: 300px"></div>
                </div>
              </div>
            </div>
          </div>
          <!--end::Mid Widgets-->

          <!--begin::Bottom Charts-->
          <div class="row gx-5 gx-xl-10">
            <div class="col-xl-4">
              <div class="card card-flush h-xl-100">
                <div class="card-header pt-7 mb-7">
                  <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-800">Warephase stats</span>
                    <span class="text-gray-400 mt-1 fw-semibold fs-6">8k social visitors</span>
                  </h3>
                  <div class="card-toolbar">
                    <a href="#" class="btn btn-sm btn-light">PDF Report</a>
                  </div>
                </div>
                <div class="card-body d-flex align-items-end pt-0">
                  <div id="kt_charts_widget_31_chart" class="w-100 h-300px"></div>
                </div>
              </div>
            </div>
            <div class="col-xl-8">
              <div class="card card-flush overflow-hidden h-xl-100">
                <div class="card-header py-5">
                  <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-dark">Human Resources</span>
                    <span class="text-gray-400 mt-1 fw-semibold fs-6">Reports by states and genders</span>
                  </h3>
                </div>
                <div class="card-body pt-0">
                  <div id="kt_charts_widget_24" class="min-h-auto" style="height: 300px"></div>
                </div>
              </div>
            </div>
          </div>
          <!--end::Bottom Charts-->

        </div>
      </div>
      <!--end::Content-->

    </div>

    <!--begin::Footer-->
    <div id="kt_app_footer" class="app-footer">
      <div class="app-container container-fluid d-flex flex-column flex-md-row flex-center flex-md-stack py-3">
        <div class="text-dark order-2 order-md-1">
          <span class="text-muted fw-semibold me-1">2023&copy;</span>
          <a href="https://keenthemes.com/" target="_blank" class="text-gray-800 text-hover-primary">Keenthemes</a>
        </div>
        <ul class="menu menu-gray-600 menu-hover-primary fw-semibold order-1">
          <li class="menu-item"><a href="https://keenthemes.com/" target="_blank" class="menu-link px-2">About</a></li>
          <li class="menu-item"><a href="https://devs.keenthemes.com/" target="_blank" class="menu-link px-2">Support</a></li>
          <li class="menu-item"><a href="https://1.envato.market/EA4JP" target="_blank" class="menu-link px-2">Purchase</a></li>
        </ul>
      </div>
    </div>
    <!--end::Footer-->

  </div>
  <!--end::Main-->
</template>
