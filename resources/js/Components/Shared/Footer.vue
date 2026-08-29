<script setup>
const ano = new Date().getFullYear();
</script>

<template>
    <!--begin::Footer-->
    <div id="kt_app_footer" class="app-footer">
        <div class="app-container container-fluid d-flex flex-column flex-md-row flex-center flex-md-stack py-3">
            <div class="text-dark">
                <span class="text-muted fw-semibold me-1">{{ ano }}&copy;</span>
                Gestão Acadêmica MosiTec
            </div>
        </div>
    </div>
    <!--end::Footer-->
</template>
