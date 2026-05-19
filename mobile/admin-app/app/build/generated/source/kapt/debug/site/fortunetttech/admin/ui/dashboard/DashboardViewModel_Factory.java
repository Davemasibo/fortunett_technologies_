package site.fortunetttech.admin.ui.dashboard;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.DashboardRepository;

@ScopeMetadata
@QualifierMetadata
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
public final class DashboardViewModel_Factory implements Factory<DashboardViewModel> {
  private final Provider<DashboardRepository> repoProvider;

  public DashboardViewModel_Factory(Provider<DashboardRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public DashboardViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static DashboardViewModel_Factory create(Provider<DashboardRepository> repoProvider) {
    return new DashboardViewModel_Factory(repoProvider);
  }

  public static DashboardViewModel newInstance(DashboardRepository repo) {
    return new DashboardViewModel(repo);
  }
}
