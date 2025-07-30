import { ref, reactive, computed } from 'vue';
import './index.css';
import FtpBackupView from './components/FtpBackupView.vue';

// Register the panel view component
panel.plugin('tearoom1/ftp-backup', {
  components: {
    'ftp-backup-view': FtpBackupView
  }
})
