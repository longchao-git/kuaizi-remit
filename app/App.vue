<script>
	import {
		listenForMemberPush
	} from './modules/remit/memberPush';

	export default {
		onLaunch() {
			listenForMemberPush();
		},
	};
</script>

<style lang="scss">
	@use './modules/remit/remit';
</style>