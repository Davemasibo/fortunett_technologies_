package site.fortunetttech.admin;

import android.app.Activity;
import android.app.Service;
import android.view.View;
import androidx.fragment.app.Fragment;
import androidx.lifecycle.SavedStateHandle;
import androidx.lifecycle.ViewModel;
import com.google.errorprone.annotations.CanIgnoreReturnValue;
import dagger.hilt.android.ActivityRetainedLifecycle;
import dagger.hilt.android.ViewModelLifecycle;
import dagger.hilt.android.internal.builders.ActivityComponentBuilder;
import dagger.hilt.android.internal.builders.ActivityRetainedComponentBuilder;
import dagger.hilt.android.internal.builders.FragmentComponentBuilder;
import dagger.hilt.android.internal.builders.ServiceComponentBuilder;
import dagger.hilt.android.internal.builders.ViewComponentBuilder;
import dagger.hilt.android.internal.builders.ViewModelComponentBuilder;
import dagger.hilt.android.internal.builders.ViewWithFragmentComponentBuilder;
import dagger.hilt.android.internal.lifecycle.DefaultViewModelFactories;
import dagger.hilt.android.internal.lifecycle.DefaultViewModelFactories_InternalFactoryFactory_Factory;
import dagger.hilt.android.internal.managers.ActivityRetainedComponentManager_LifecycleModule_ProvideActivityRetainedLifecycleFactory;
import dagger.hilt.android.internal.managers.SavedStateHandleHolder;
import dagger.hilt.android.internal.modules.ApplicationContextModule;
import dagger.hilt.android.internal.modules.ApplicationContextModule_ProvideContextFactory;
import dagger.internal.DaggerGenerated;
import dagger.internal.DoubleCheck;
import dagger.internal.IdentifierNameString;
import dagger.internal.KeepFieldType;
import dagger.internal.LazyClassKeyMap;
import dagger.internal.MapBuilder;
import dagger.internal.Preconditions;
import dagger.internal.Provider;
import java.util.Collections;
import java.util.Map;
import java.util.Set;
import javax.annotation.processing.Generated;
import site.fortunetttech.admin.data.network.ApiService;
import site.fortunetttech.admin.data.preferences.TokenPreferences;
import site.fortunetttech.admin.data.repository.AuthRepository;
import site.fortunetttech.admin.data.repository.ClientRepository;
import site.fortunetttech.admin.data.repository.DashboardRepository;
import site.fortunetttech.admin.data.repository.PackageRepository;
import site.fortunetttech.admin.data.repository.PaymentRepository;
import site.fortunetttech.admin.data.repository.RouterRepository;
import site.fortunetttech.admin.data.repository.VoucherRepository;
import site.fortunetttech.admin.di.NetworkModule_ProvideApiServiceFactory;
import site.fortunetttech.admin.ui.auth.LoginActivity;
import site.fortunetttech.admin.ui.auth.LoginActivity_MembersInjector;
import site.fortunetttech.admin.ui.auth.LoginViewModel;
import site.fortunetttech.admin.ui.auth.LoginViewModel_HiltModules;
import site.fortunetttech.admin.ui.clients.AddClientFragment;
import site.fortunetttech.admin.ui.clients.AddClientViewModel;
import site.fortunetttech.admin.ui.clients.AddClientViewModel_HiltModules;
import site.fortunetttech.admin.ui.clients.ClientDetailFragment;
import site.fortunetttech.admin.ui.clients.ClientDetailViewModel;
import site.fortunetttech.admin.ui.clients.ClientDetailViewModel_HiltModules;
import site.fortunetttech.admin.ui.clients.ClientListFragment;
import site.fortunetttech.admin.ui.clients.ClientListViewModel;
import site.fortunetttech.admin.ui.clients.ClientListViewModel_HiltModules;
import site.fortunetttech.admin.ui.dashboard.DashboardFragment;
import site.fortunetttech.admin.ui.dashboard.DashboardViewModel;
import site.fortunetttech.admin.ui.dashboard.DashboardViewModel_HiltModules;
import site.fortunetttech.admin.ui.packages.PackagesFragment;
import site.fortunetttech.admin.ui.packages.PackagesViewModel;
import site.fortunetttech.admin.ui.packages.PackagesViewModel_HiltModules;
import site.fortunetttech.admin.ui.payments.PaymentsFragment;
import site.fortunetttech.admin.ui.payments.PaymentsViewModel;
import site.fortunetttech.admin.ui.payments.PaymentsViewModel_HiltModules;
import site.fortunetttech.admin.ui.routers.RoutersFragment;
import site.fortunetttech.admin.ui.routers.RoutersViewModel;
import site.fortunetttech.admin.ui.routers.RoutersViewModel_HiltModules;
import site.fortunetttech.admin.ui.vouchers.VouchersFragment;
import site.fortunetttech.admin.ui.vouchers.VouchersViewModel;
import site.fortunetttech.admin.ui.vouchers.VouchersViewModel_HiltModules;

@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class DaggerAdminApp_HiltComponents_SingletonC {
  private DaggerAdminApp_HiltComponents_SingletonC() {
  }

  public static Builder builder() {
    return new Builder();
  }

  public static final class Builder {
    private ApplicationContextModule applicationContextModule;

    private Builder() {
    }

    public Builder applicationContextModule(ApplicationContextModule applicationContextModule) {
      this.applicationContextModule = Preconditions.checkNotNull(applicationContextModule);
      return this;
    }

    public AdminApp_HiltComponents.SingletonC build() {
      Preconditions.checkBuilderRequirement(applicationContextModule, ApplicationContextModule.class);
      return new SingletonCImpl(applicationContextModule);
    }
  }

  private static final class ActivityRetainedCBuilder implements AdminApp_HiltComponents.ActivityRetainedC.Builder {
    private final SingletonCImpl singletonCImpl;

    private SavedStateHandleHolder savedStateHandleHolder;

    private ActivityRetainedCBuilder(SingletonCImpl singletonCImpl) {
      this.singletonCImpl = singletonCImpl;
    }

    @Override
    public ActivityRetainedCBuilder savedStateHandleHolder(
        SavedStateHandleHolder savedStateHandleHolder) {
      this.savedStateHandleHolder = Preconditions.checkNotNull(savedStateHandleHolder);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ActivityRetainedC build() {
      Preconditions.checkBuilderRequirement(savedStateHandleHolder, SavedStateHandleHolder.class);
      return new ActivityRetainedCImpl(singletonCImpl, savedStateHandleHolder);
    }
  }

  private static final class ActivityCBuilder implements AdminApp_HiltComponents.ActivityC.Builder {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private Activity activity;

    private ActivityCBuilder(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
    }

    @Override
    public ActivityCBuilder activity(Activity activity) {
      this.activity = Preconditions.checkNotNull(activity);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ActivityC build() {
      Preconditions.checkBuilderRequirement(activity, Activity.class);
      return new ActivityCImpl(singletonCImpl, activityRetainedCImpl, activity);
    }
  }

  private static final class FragmentCBuilder implements AdminApp_HiltComponents.FragmentC.Builder {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private Fragment fragment;

    private FragmentCBuilder(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, ActivityCImpl activityCImpl) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;
    }

    @Override
    public FragmentCBuilder fragment(Fragment fragment) {
      this.fragment = Preconditions.checkNotNull(fragment);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.FragmentC build() {
      Preconditions.checkBuilderRequirement(fragment, Fragment.class);
      return new FragmentCImpl(singletonCImpl, activityRetainedCImpl, activityCImpl, fragment);
    }
  }

  private static final class ViewWithFragmentCBuilder implements AdminApp_HiltComponents.ViewWithFragmentC.Builder {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private final FragmentCImpl fragmentCImpl;

    private View view;

    private ViewWithFragmentCBuilder(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, ActivityCImpl activityCImpl,
        FragmentCImpl fragmentCImpl) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;
      this.fragmentCImpl = fragmentCImpl;
    }

    @Override
    public ViewWithFragmentCBuilder view(View view) {
      this.view = Preconditions.checkNotNull(view);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ViewWithFragmentC build() {
      Preconditions.checkBuilderRequirement(view, View.class);
      return new ViewWithFragmentCImpl(singletonCImpl, activityRetainedCImpl, activityCImpl, fragmentCImpl, view);
    }
  }

  private static final class ViewCBuilder implements AdminApp_HiltComponents.ViewC.Builder {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private View view;

    private ViewCBuilder(SingletonCImpl singletonCImpl, ActivityRetainedCImpl activityRetainedCImpl,
        ActivityCImpl activityCImpl) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;
    }

    @Override
    public ViewCBuilder view(View view) {
      this.view = Preconditions.checkNotNull(view);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ViewC build() {
      Preconditions.checkBuilderRequirement(view, View.class);
      return new ViewCImpl(singletonCImpl, activityRetainedCImpl, activityCImpl, view);
    }
  }

  private static final class ViewModelCBuilder implements AdminApp_HiltComponents.ViewModelC.Builder {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private SavedStateHandle savedStateHandle;

    private ViewModelLifecycle viewModelLifecycle;

    private ViewModelCBuilder(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
    }

    @Override
    public ViewModelCBuilder savedStateHandle(SavedStateHandle handle) {
      this.savedStateHandle = Preconditions.checkNotNull(handle);
      return this;
    }

    @Override
    public ViewModelCBuilder viewModelLifecycle(ViewModelLifecycle viewModelLifecycle) {
      this.viewModelLifecycle = Preconditions.checkNotNull(viewModelLifecycle);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ViewModelC build() {
      Preconditions.checkBuilderRequirement(savedStateHandle, SavedStateHandle.class);
      Preconditions.checkBuilderRequirement(viewModelLifecycle, ViewModelLifecycle.class);
      return new ViewModelCImpl(singletonCImpl, activityRetainedCImpl, savedStateHandle, viewModelLifecycle);
    }
  }

  private static final class ServiceCBuilder implements AdminApp_HiltComponents.ServiceC.Builder {
    private final SingletonCImpl singletonCImpl;

    private Service service;

    private ServiceCBuilder(SingletonCImpl singletonCImpl) {
      this.singletonCImpl = singletonCImpl;
    }

    @Override
    public ServiceCBuilder service(Service service) {
      this.service = Preconditions.checkNotNull(service);
      return this;
    }

    @Override
    public AdminApp_HiltComponents.ServiceC build() {
      Preconditions.checkBuilderRequirement(service, Service.class);
      return new ServiceCImpl(singletonCImpl, service);
    }
  }

  private static final class ViewWithFragmentCImpl extends AdminApp_HiltComponents.ViewWithFragmentC {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private final FragmentCImpl fragmentCImpl;

    private final ViewWithFragmentCImpl viewWithFragmentCImpl = this;

    private ViewWithFragmentCImpl(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, ActivityCImpl activityCImpl,
        FragmentCImpl fragmentCImpl, View viewParam) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;
      this.fragmentCImpl = fragmentCImpl;


    }
  }

  private static final class FragmentCImpl extends AdminApp_HiltComponents.FragmentC {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private final FragmentCImpl fragmentCImpl = this;

    private FragmentCImpl(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, ActivityCImpl activityCImpl,
        Fragment fragmentParam) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;


    }

    @Override
    public DefaultViewModelFactories.InternalFactoryFactory getHiltInternalFactoryFactory() {
      return activityCImpl.getHiltInternalFactoryFactory();
    }

    @Override
    public ViewWithFragmentComponentBuilder viewWithFragmentComponentBuilder() {
      return new ViewWithFragmentCBuilder(singletonCImpl, activityRetainedCImpl, activityCImpl, fragmentCImpl);
    }

    @Override
    public void injectAddClientFragment(AddClientFragment arg0) {
    }

    @Override
    public void injectClientDetailFragment(ClientDetailFragment arg0) {
    }

    @Override
    public void injectClientListFragment(ClientListFragment arg0) {
    }

    @Override
    public void injectDashboardFragment(DashboardFragment arg0) {
    }

    @Override
    public void injectPackagesFragment(PackagesFragment arg0) {
    }

    @Override
    public void injectPaymentsFragment(PaymentsFragment arg0) {
    }

    @Override
    public void injectRoutersFragment(RoutersFragment arg0) {
    }

    @Override
    public void injectVouchersFragment(VouchersFragment arg0) {
    }
  }

  private static final class ViewCImpl extends AdminApp_HiltComponents.ViewC {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl;

    private final ViewCImpl viewCImpl = this;

    private ViewCImpl(SingletonCImpl singletonCImpl, ActivityRetainedCImpl activityRetainedCImpl,
        ActivityCImpl activityCImpl, View viewParam) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.activityCImpl = activityCImpl;


    }
  }

  private static final class ActivityCImpl extends AdminApp_HiltComponents.ActivityC {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ActivityCImpl activityCImpl = this;

    private ActivityCImpl(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, Activity activityParam) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;


    }

    @Override
    public DefaultViewModelFactories.InternalFactoryFactory getHiltInternalFactoryFactory() {
      return DefaultViewModelFactories_InternalFactoryFactory_Factory.newInstance(getViewModelKeys(), new ViewModelCBuilder(singletonCImpl, activityRetainedCImpl));
    }

    @Override
    public Map<Class<?>, Boolean> getViewModelKeys() {
      return LazyClassKeyMap.<Boolean>of(MapBuilder.<String, Boolean>newMapBuilder(9).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_AddClientViewModel, AddClientViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_ClientDetailViewModel, ClientDetailViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_ClientListViewModel, ClientListViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_dashboard_DashboardViewModel, DashboardViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_auth_LoginViewModel, LoginViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_packages_PackagesViewModel, PackagesViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_payments_PaymentsViewModel, PaymentsViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_routers_RoutersViewModel, RoutersViewModel_HiltModules.KeyModule.provide()).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_vouchers_VouchersViewModel, VouchersViewModel_HiltModules.KeyModule.provide()).build());
    }

    @Override
    public ViewModelComponentBuilder getViewModelComponentBuilder() {
      return new ViewModelCBuilder(singletonCImpl, activityRetainedCImpl);
    }

    @Override
    public FragmentComponentBuilder fragmentComponentBuilder() {
      return new FragmentCBuilder(singletonCImpl, activityRetainedCImpl, activityCImpl);
    }

    @Override
    public ViewComponentBuilder viewComponentBuilder() {
      return new ViewCBuilder(singletonCImpl, activityRetainedCImpl, activityCImpl);
    }

    @Override
    public void injectMainActivity(MainActivity arg0) {
      injectMainActivity2(arg0);
    }

    @Override
    public void injectLoginActivity(LoginActivity arg0) {
      injectLoginActivity2(arg0);
    }

    @CanIgnoreReturnValue
    private MainActivity injectMainActivity2(MainActivity instance) {
      MainActivity_MembersInjector.injectPrefs(instance, singletonCImpl.tokenPreferencesProvider.get());
      return instance;
    }

    @CanIgnoreReturnValue
    private LoginActivity injectLoginActivity2(LoginActivity instance) {
      LoginActivity_MembersInjector.injectPrefs(instance, singletonCImpl.tokenPreferencesProvider.get());
      return instance;
    }

    @IdentifierNameString
    private static final class LazyClassKeyProvider {
      static String site_fortunetttech_admin_ui_payments_PaymentsViewModel = "site.fortunetttech.admin.ui.payments.PaymentsViewModel";

      static String site_fortunetttech_admin_ui_vouchers_VouchersViewModel = "site.fortunetttech.admin.ui.vouchers.VouchersViewModel";

      static String site_fortunetttech_admin_ui_auth_LoginViewModel = "site.fortunetttech.admin.ui.auth.LoginViewModel";

      static String site_fortunetttech_admin_ui_routers_RoutersViewModel = "site.fortunetttech.admin.ui.routers.RoutersViewModel";

      static String site_fortunetttech_admin_ui_clients_AddClientViewModel = "site.fortunetttech.admin.ui.clients.AddClientViewModel";

      static String site_fortunetttech_admin_ui_dashboard_DashboardViewModel = "site.fortunetttech.admin.ui.dashboard.DashboardViewModel";

      static String site_fortunetttech_admin_ui_clients_ClientDetailViewModel = "site.fortunetttech.admin.ui.clients.ClientDetailViewModel";

      static String site_fortunetttech_admin_ui_clients_ClientListViewModel = "site.fortunetttech.admin.ui.clients.ClientListViewModel";

      static String site_fortunetttech_admin_ui_packages_PackagesViewModel = "site.fortunetttech.admin.ui.packages.PackagesViewModel";

      @KeepFieldType
      PaymentsViewModel site_fortunetttech_admin_ui_payments_PaymentsViewModel2;

      @KeepFieldType
      VouchersViewModel site_fortunetttech_admin_ui_vouchers_VouchersViewModel2;

      @KeepFieldType
      LoginViewModel site_fortunetttech_admin_ui_auth_LoginViewModel2;

      @KeepFieldType
      RoutersViewModel site_fortunetttech_admin_ui_routers_RoutersViewModel2;

      @KeepFieldType
      AddClientViewModel site_fortunetttech_admin_ui_clients_AddClientViewModel2;

      @KeepFieldType
      DashboardViewModel site_fortunetttech_admin_ui_dashboard_DashboardViewModel2;

      @KeepFieldType
      ClientDetailViewModel site_fortunetttech_admin_ui_clients_ClientDetailViewModel2;

      @KeepFieldType
      ClientListViewModel site_fortunetttech_admin_ui_clients_ClientListViewModel2;

      @KeepFieldType
      PackagesViewModel site_fortunetttech_admin_ui_packages_PackagesViewModel2;
    }
  }

  private static final class ViewModelCImpl extends AdminApp_HiltComponents.ViewModelC {
    private final SavedStateHandle savedStateHandle;

    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl;

    private final ViewModelCImpl viewModelCImpl = this;

    private Provider<AddClientViewModel> addClientViewModelProvider;

    private Provider<ClientDetailViewModel> clientDetailViewModelProvider;

    private Provider<ClientListViewModel> clientListViewModelProvider;

    private Provider<DashboardViewModel> dashboardViewModelProvider;

    private Provider<LoginViewModel> loginViewModelProvider;

    private Provider<PackagesViewModel> packagesViewModelProvider;

    private Provider<PaymentsViewModel> paymentsViewModelProvider;

    private Provider<RoutersViewModel> routersViewModelProvider;

    private Provider<VouchersViewModel> vouchersViewModelProvider;

    private ViewModelCImpl(SingletonCImpl singletonCImpl,
        ActivityRetainedCImpl activityRetainedCImpl, SavedStateHandle savedStateHandleParam,
        ViewModelLifecycle viewModelLifecycleParam) {
      this.singletonCImpl = singletonCImpl;
      this.activityRetainedCImpl = activityRetainedCImpl;
      this.savedStateHandle = savedStateHandleParam;
      initialize(savedStateHandleParam, viewModelLifecycleParam);

    }

    @SuppressWarnings("unchecked")
    private void initialize(final SavedStateHandle savedStateHandleParam,
        final ViewModelLifecycle viewModelLifecycleParam) {
      this.addClientViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 0);
      this.clientDetailViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 1);
      this.clientListViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 2);
      this.dashboardViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 3);
      this.loginViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 4);
      this.packagesViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 5);
      this.paymentsViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 6);
      this.routersViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 7);
      this.vouchersViewModelProvider = new SwitchingProvider<>(singletonCImpl, activityRetainedCImpl, viewModelCImpl, 8);
    }

    @Override
    public Map<Class<?>, javax.inject.Provider<ViewModel>> getHiltViewModelMap() {
      return LazyClassKeyMap.<javax.inject.Provider<ViewModel>>of(MapBuilder.<String, javax.inject.Provider<ViewModel>>newMapBuilder(9).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_AddClientViewModel, ((Provider) addClientViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_ClientDetailViewModel, ((Provider) clientDetailViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_clients_ClientListViewModel, ((Provider) clientListViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_dashboard_DashboardViewModel, ((Provider) dashboardViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_auth_LoginViewModel, ((Provider) loginViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_packages_PackagesViewModel, ((Provider) packagesViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_payments_PaymentsViewModel, ((Provider) paymentsViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_routers_RoutersViewModel, ((Provider) routersViewModelProvider)).put(LazyClassKeyProvider.site_fortunetttech_admin_ui_vouchers_VouchersViewModel, ((Provider) vouchersViewModelProvider)).build());
    }

    @Override
    public Map<Class<?>, Object> getHiltViewModelAssistedMap() {
      return Collections.<Class<?>, Object>emptyMap();
    }

    @IdentifierNameString
    private static final class LazyClassKeyProvider {
      static String site_fortunetttech_admin_ui_payments_PaymentsViewModel = "site.fortunetttech.admin.ui.payments.PaymentsViewModel";

      static String site_fortunetttech_admin_ui_vouchers_VouchersViewModel = "site.fortunetttech.admin.ui.vouchers.VouchersViewModel";

      static String site_fortunetttech_admin_ui_packages_PackagesViewModel = "site.fortunetttech.admin.ui.packages.PackagesViewModel";

      static String site_fortunetttech_admin_ui_clients_ClientListViewModel = "site.fortunetttech.admin.ui.clients.ClientListViewModel";

      static String site_fortunetttech_admin_ui_routers_RoutersViewModel = "site.fortunetttech.admin.ui.routers.RoutersViewModel";

      static String site_fortunetttech_admin_ui_clients_AddClientViewModel = "site.fortunetttech.admin.ui.clients.AddClientViewModel";

      static String site_fortunetttech_admin_ui_clients_ClientDetailViewModel = "site.fortunetttech.admin.ui.clients.ClientDetailViewModel";

      static String site_fortunetttech_admin_ui_dashboard_DashboardViewModel = "site.fortunetttech.admin.ui.dashboard.DashboardViewModel";

      static String site_fortunetttech_admin_ui_auth_LoginViewModel = "site.fortunetttech.admin.ui.auth.LoginViewModel";

      @KeepFieldType
      PaymentsViewModel site_fortunetttech_admin_ui_payments_PaymentsViewModel2;

      @KeepFieldType
      VouchersViewModel site_fortunetttech_admin_ui_vouchers_VouchersViewModel2;

      @KeepFieldType
      PackagesViewModel site_fortunetttech_admin_ui_packages_PackagesViewModel2;

      @KeepFieldType
      ClientListViewModel site_fortunetttech_admin_ui_clients_ClientListViewModel2;

      @KeepFieldType
      RoutersViewModel site_fortunetttech_admin_ui_routers_RoutersViewModel2;

      @KeepFieldType
      AddClientViewModel site_fortunetttech_admin_ui_clients_AddClientViewModel2;

      @KeepFieldType
      ClientDetailViewModel site_fortunetttech_admin_ui_clients_ClientDetailViewModel2;

      @KeepFieldType
      DashboardViewModel site_fortunetttech_admin_ui_dashboard_DashboardViewModel2;

      @KeepFieldType
      LoginViewModel site_fortunetttech_admin_ui_auth_LoginViewModel2;
    }

    private static final class SwitchingProvider<T> implements Provider<T> {
      private final SingletonCImpl singletonCImpl;

      private final ActivityRetainedCImpl activityRetainedCImpl;

      private final ViewModelCImpl viewModelCImpl;

      private final int id;

      SwitchingProvider(SingletonCImpl singletonCImpl, ActivityRetainedCImpl activityRetainedCImpl,
          ViewModelCImpl viewModelCImpl, int id) {
        this.singletonCImpl = singletonCImpl;
        this.activityRetainedCImpl = activityRetainedCImpl;
        this.viewModelCImpl = viewModelCImpl;
        this.id = id;
      }

      @SuppressWarnings("unchecked")
      @Override
      public T get() {
        switch (id) {
          case 0: // site.fortunetttech.admin.ui.clients.AddClientViewModel 
          return (T) new AddClientViewModel(singletonCImpl.clientRepositoryProvider.get(), singletonCImpl.packageRepositoryProvider.get());

          case 1: // site.fortunetttech.admin.ui.clients.ClientDetailViewModel 
          return (T) new ClientDetailViewModel(singletonCImpl.clientRepositoryProvider.get(), singletonCImpl.packageRepositoryProvider.get(), singletonCImpl.paymentRepositoryProvider.get(), viewModelCImpl.savedStateHandle);

          case 2: // site.fortunetttech.admin.ui.clients.ClientListViewModel 
          return (T) new ClientListViewModel(singletonCImpl.clientRepositoryProvider.get());

          case 3: // site.fortunetttech.admin.ui.dashboard.DashboardViewModel 
          return (T) new DashboardViewModel(singletonCImpl.dashboardRepositoryProvider.get());

          case 4: // site.fortunetttech.admin.ui.auth.LoginViewModel 
          return (T) new LoginViewModel(singletonCImpl.authRepositoryProvider.get());

          case 5: // site.fortunetttech.admin.ui.packages.PackagesViewModel 
          return (T) new PackagesViewModel(singletonCImpl.packageRepositoryProvider.get());

          case 6: // site.fortunetttech.admin.ui.payments.PaymentsViewModel 
          return (T) new PaymentsViewModel(singletonCImpl.paymentRepositoryProvider.get());

          case 7: // site.fortunetttech.admin.ui.routers.RoutersViewModel 
          return (T) new RoutersViewModel(singletonCImpl.routerRepositoryProvider.get());

          case 8: // site.fortunetttech.admin.ui.vouchers.VouchersViewModel 
          return (T) new VouchersViewModel(singletonCImpl.voucherRepositoryProvider.get(), singletonCImpl.packageRepositoryProvider.get());

          default: throw new AssertionError(id);
        }
      }
    }
  }

  private static final class ActivityRetainedCImpl extends AdminApp_HiltComponents.ActivityRetainedC {
    private final SingletonCImpl singletonCImpl;

    private final ActivityRetainedCImpl activityRetainedCImpl = this;

    private Provider<ActivityRetainedLifecycle> provideActivityRetainedLifecycleProvider;

    private ActivityRetainedCImpl(SingletonCImpl singletonCImpl,
        SavedStateHandleHolder savedStateHandleHolderParam) {
      this.singletonCImpl = singletonCImpl;

      initialize(savedStateHandleHolderParam);

    }

    @SuppressWarnings("unchecked")
    private void initialize(final SavedStateHandleHolder savedStateHandleHolderParam) {
      this.provideActivityRetainedLifecycleProvider = DoubleCheck.provider(new SwitchingProvider<ActivityRetainedLifecycle>(singletonCImpl, activityRetainedCImpl, 0));
    }

    @Override
    public ActivityComponentBuilder activityComponentBuilder() {
      return new ActivityCBuilder(singletonCImpl, activityRetainedCImpl);
    }

    @Override
    public ActivityRetainedLifecycle getActivityRetainedLifecycle() {
      return provideActivityRetainedLifecycleProvider.get();
    }

    private static final class SwitchingProvider<T> implements Provider<T> {
      private final SingletonCImpl singletonCImpl;

      private final ActivityRetainedCImpl activityRetainedCImpl;

      private final int id;

      SwitchingProvider(SingletonCImpl singletonCImpl, ActivityRetainedCImpl activityRetainedCImpl,
          int id) {
        this.singletonCImpl = singletonCImpl;
        this.activityRetainedCImpl = activityRetainedCImpl;
        this.id = id;
      }

      @SuppressWarnings("unchecked")
      @Override
      public T get() {
        switch (id) {
          case 0: // dagger.hilt.android.ActivityRetainedLifecycle 
          return (T) ActivityRetainedComponentManager_LifecycleModule_ProvideActivityRetainedLifecycleFactory.provideActivityRetainedLifecycle();

          default: throw new AssertionError(id);
        }
      }
    }
  }

  private static final class ServiceCImpl extends AdminApp_HiltComponents.ServiceC {
    private final SingletonCImpl singletonCImpl;

    private final ServiceCImpl serviceCImpl = this;

    private ServiceCImpl(SingletonCImpl singletonCImpl, Service serviceParam) {
      this.singletonCImpl = singletonCImpl;


    }
  }

  private static final class SingletonCImpl extends AdminApp_HiltComponents.SingletonC {
    private final ApplicationContextModule applicationContextModule;

    private final SingletonCImpl singletonCImpl = this;

    private Provider<TokenPreferences> tokenPreferencesProvider;

    private Provider<ApiService> provideApiServiceProvider;

    private Provider<ClientRepository> clientRepositoryProvider;

    private Provider<PackageRepository> packageRepositoryProvider;

    private Provider<PaymentRepository> paymentRepositoryProvider;

    private Provider<DashboardRepository> dashboardRepositoryProvider;

    private Provider<AuthRepository> authRepositoryProvider;

    private Provider<RouterRepository> routerRepositoryProvider;

    private Provider<VoucherRepository> voucherRepositoryProvider;

    private SingletonCImpl(ApplicationContextModule applicationContextModuleParam) {
      this.applicationContextModule = applicationContextModuleParam;
      initialize(applicationContextModuleParam);

    }

    @SuppressWarnings("unchecked")
    private void initialize(final ApplicationContextModule applicationContextModuleParam) {
      this.tokenPreferencesProvider = DoubleCheck.provider(new SwitchingProvider<TokenPreferences>(singletonCImpl, 0));
      this.provideApiServiceProvider = DoubleCheck.provider(new SwitchingProvider<ApiService>(singletonCImpl, 2));
      this.clientRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<ClientRepository>(singletonCImpl, 1));
      this.packageRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<PackageRepository>(singletonCImpl, 3));
      this.paymentRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<PaymentRepository>(singletonCImpl, 4));
      this.dashboardRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<DashboardRepository>(singletonCImpl, 5));
      this.authRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<AuthRepository>(singletonCImpl, 6));
      this.routerRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<RouterRepository>(singletonCImpl, 7));
      this.voucherRepositoryProvider = DoubleCheck.provider(new SwitchingProvider<VoucherRepository>(singletonCImpl, 8));
    }

    @Override
    public Set<Boolean> getDisableFragmentGetContextFix() {
      return Collections.<Boolean>emptySet();
    }

    @Override
    public ActivityRetainedComponentBuilder retainedComponentBuilder() {
      return new ActivityRetainedCBuilder(singletonCImpl);
    }

    @Override
    public ServiceComponentBuilder serviceComponentBuilder() {
      return new ServiceCBuilder(singletonCImpl);
    }

    @Override
    public void injectAdminApp(AdminApp arg0) {
    }

    private static final class SwitchingProvider<T> implements Provider<T> {
      private final SingletonCImpl singletonCImpl;

      private final int id;

      SwitchingProvider(SingletonCImpl singletonCImpl, int id) {
        this.singletonCImpl = singletonCImpl;
        this.id = id;
      }

      @SuppressWarnings("unchecked")
      @Override
      public T get() {
        switch (id) {
          case 0: // site.fortunetttech.admin.data.preferences.TokenPreferences 
          return (T) new TokenPreferences(ApplicationContextModule_ProvideContextFactory.provideContext(singletonCImpl.applicationContextModule));

          case 1: // site.fortunetttech.admin.data.repository.ClientRepository 
          return (T) new ClientRepository(singletonCImpl.provideApiServiceProvider.get());

          case 2: // site.fortunetttech.admin.data.network.ApiService 
          return (T) NetworkModule_ProvideApiServiceFactory.provideApiService(singletonCImpl.tokenPreferencesProvider.get());

          case 3: // site.fortunetttech.admin.data.repository.PackageRepository 
          return (T) new PackageRepository(singletonCImpl.provideApiServiceProvider.get());

          case 4: // site.fortunetttech.admin.data.repository.PaymentRepository 
          return (T) new PaymentRepository(singletonCImpl.provideApiServiceProvider.get());

          case 5: // site.fortunetttech.admin.data.repository.DashboardRepository 
          return (T) new DashboardRepository(singletonCImpl.provideApiServiceProvider.get());

          case 6: // site.fortunetttech.admin.data.repository.AuthRepository 
          return (T) new AuthRepository(singletonCImpl.provideApiServiceProvider.get(), singletonCImpl.tokenPreferencesProvider.get());

          case 7: // site.fortunetttech.admin.data.repository.RouterRepository 
          return (T) new RouterRepository(singletonCImpl.provideApiServiceProvider.get());

          case 8: // site.fortunetttech.admin.data.repository.VoucherRepository 
          return (T) new VoucherRepository(singletonCImpl.provideApiServiceProvider.get());

          default: throw new AssertionError(id);
        }
      }
    }
  }
}
