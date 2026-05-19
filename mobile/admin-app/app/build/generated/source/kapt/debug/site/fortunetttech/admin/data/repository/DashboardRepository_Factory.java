package site.fortunetttech.admin.data.repository;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.network.ApiService;

@ScopeMetadata("javax.inject.Singleton")
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
public final class DashboardRepository_Factory implements Factory<DashboardRepository> {
  private final Provider<ApiService> apiProvider;

  public DashboardRepository_Factory(Provider<ApiService> apiProvider) {
    this.apiProvider = apiProvider;
  }

  @Override
  public DashboardRepository get() {
    return newInstance(apiProvider.get());
  }

  public static DashboardRepository_Factory create(Provider<ApiService> apiProvider) {
    return new DashboardRepository_Factory(apiProvider);
  }

  public static DashboardRepository newInstance(ApiService api) {
    return new DashboardRepository(api);
  }
}
