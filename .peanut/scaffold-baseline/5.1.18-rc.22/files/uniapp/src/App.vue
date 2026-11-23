<script setup lang="ts">
  import { onLaunch, onShow, onHide } from '@dcloudio/uni-app';
  import { useAppStore } from '@/store/app';

  const appStore = useAppStore();

  onLaunch(async () => {
    try {
      const config = await appStore.loadConfig();
      // #ifdef H5
      if (config?.web_page.status === 0) {
        const redirectUrl = config.web_page.page_url.trim();
        const target =
          config.web_page.page_status === 1 && redirectUrl
            ? redirectUrl
            : 'about:blank';
        window.location.replace(target);
      }
      // #endif
    } catch {
      // A failed public configuration request must not silently bypass the H5 guard.
      // #ifdef H5
      window.location.replace('about:blank');
      // #endif
    }
  });
  onShow(() => {
    console.log('App Show');
  });
  onHide(() => {
    console.log('App Hide');
  });
</script>
<style>
  /* #ifdef H5 */
  /* The native page shadow must not require DCloud's external image CDN. */
  body::after {
    animation: none;
  }
  html .uni-page-head-shadow-grey::after,
  html .uni-page-head-shadow-blue::after,
  html .uni-page-head-shadow-green::after,
  html .uni-page-head-shadow-orange::after,
  html .uni-page-head-shadow-red::after,
  html .uni-page-head-shadow-yellow::after {
    background-image: linear-gradient(
      to bottom,
      var(--peanut-navigation-shadow, rgb(0 0 0 / 12%)),
      transparent
    );
  }
  html .uni-page-head-shadow-blue::after {
    --peanut-navigation-shadow: rgb(0 122 255 / 12%);
  }
  html .uni-page-head-shadow-green::after {
    --peanut-navigation-shadow: rgb(7 193 96 / 12%);
  }
  html .uni-page-head-shadow-orange::after {
    --peanut-navigation-shadow: rgb(255 149 0 / 12%);
  }
  html .uni-page-head-shadow-red::after {
    --peanut-navigation-shadow: rgb(255 59 48 / 12%);
  }
  html .uni-page-head-shadow-yellow::after {
    --peanut-navigation-shadow: rgb(255 204 0 / 12%);
  }
  /* #endif */
</style>
