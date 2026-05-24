<template>
    <div class="menu menu-sub menu-sub-dropdown menu-column w-100 w-sm-350px" data-kt-menu="true">
        <div class="card">
            <div class="card-header">
                <div class="card-title">My Apps</div>
                <div class="card-toolbar">
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary me-n3"
                        data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                        data-kt-menu-placement="bottom-end"
                    >
                        <i class="ki-duotone ki-setting-3 fs-2">
                            <span v-for="n in 5" :key="n" :class="`path${n}`"></span>
                        </i>
                    </button>
                </div>
            </div>
            <div class="card-body py-5">
                <div class="mh-450px scroll-y me-n5 pe-5">
                    <div class="row g-2">
                        <div v-for="app in apps" :key="app.label" class="col-4">
                            <a href="#" class="d-flex flex-column flex-center text-center text-gray-800 text-hover-primary bg-hover-light rounded py-4 px-3 mb-3">
                                <img :src="app.logo" class="w-25px h-25px mb-2" alt="" />
                                <span class="fw-semibold">{{ app.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const apps = [
    { label: 'AWS', logo: '/themes/metronic/assets/media/svg/brand-logos/amazon.svg' },
    { label: 'AngularJS', logo: '/themes/metronic/assets/media/svg/brand-logos/angular-icon-1.svg' },
    { label: 'Atica', logo: '/themes/metronic/assets/media/svg/brand-logos/atica.svg' },
    { label: 'Music', logo: '/themes/metronic/assets/media/svg/brand-logos/beats-electronics.svg' },
    { label: 'Codeigniter', logo: '/themes/metronic/assets/media/svg/brand-logos/codeigniter.svg' },
    { label: 'Bootstrap', logo: '/themes/metronic/assets/media/svg/brand-logos/bootstrap-4.svg' },
    { label: 'GTM', logo: '/themes/metronic/assets/media/svg/brand-logos/google-tag-manager.svg' },
    { label: 'Disqus', logo: '/themes/metronic/assets/media/svg/brand-logos/disqus.svg' },
    { label: 'Dribble', logo: '/themes/metronic/assets/media/svg/brand-logos/dribbble-icon-1.svg' },
    { label: 'Play Store', logo: '/themes/metronic/assets/media/svg/brand-logos/google-play-store.svg' },
    { label: 'Podcasts', logo: '/themes/metronic/assets/media/svg/brand-logos/google-podcasts.svg' },
    { label: 'Figma', logo: '/themes/metronic/assets/media/svg/brand-logos/figma-1.svg' },
    { label: 'Github', logo: '/themes/metronic/assets/media/svg/brand-logos/github.svg' },
    { label: 'Gitlab', logo: '/themes/metronic/assets/media/svg/brand-logos/gitlab.svg' },
    { label: 'Instagram', logo: '/themes/metronic/assets/media/svg/brand-logos/instagram-2-1.svg' },
    { label: 'Pinterest', logo: '/themes/metronic/assets/media/svg/brand-logos/pinterest-p.svg' },
]
</script>
